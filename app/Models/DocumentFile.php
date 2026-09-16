<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

/**
 * document_files table access. Each row is one uploaded version of a
 * submission (FR-7, FR-8); files themselves are stored outside the web root
 * and served only through DocumentController's authenticated download route.
 */
final class DocumentFile
{
    /**
     * Insert a new file version for a submission. Returns the new file_id.
     */
    public static function create(
        int $submissionId,
        int $uploadedBy,
        string $fileName,
        string $filePath,
        string $mimeType,
        int $fileSize,
        int $versionNo
    ): int {
        $stmt = self::pdo()->prepare(
            'INSERT INTO document_files (submission_id, uploaded_by, file_name, file_path, mime_type, file_size, version_no)
             VALUES (:submission_id, :uploaded_by, :file_name, :file_path, :mime_type, :file_size, :version_no)'
        );
        $stmt->execute([
            ':submission_id' => $submissionId,
            ':uploaded_by'   => $uploadedBy,
            ':file_name'     => $fileName,
            ':file_path'     => $filePath,
            ':mime_type'     => $mimeType,
            ':file_size'     => $fileSize,
            ':version_no'    => $versionNo,
        ]);

        return (int) self::pdo()->lastInsertId();
    }

    /**
     * A file joined to its owning submission's faculty_id, for the download
     * route's access-control check (IDOR protection).
     *
     * @return array{file_id:int,file_name:string,file_path:string,mime_type:string,faculty_id:int}|null
     */
    public static function findWithOwner(int $fileId): ?array
    {
        $stmt = self::pdo()->prepare(
            'SELECT f.file_id, f.file_name, f.file_path, f.mime_type, s.faculty_id
             FROM document_files f
             INNER JOIN submissions s ON s.submission_id = f.submission_id
             WHERE f.file_id = :id
             LIMIT 1'
        );
        $stmt->execute([':id' => $fileId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Every uploaded version of one submission, newest version first — the
     * version history on the document-detail page (FR-11).
     *
     * Resubmitting a returned document inserts a NEW row rather than replacing
     * the old one (FR-10), so prior versions remain downloadable evidence of
     * what was submitted and when. `uploaded_by_name` is joined in because a
     * version is only meaningful alongside who uploaded it.
     *
     * @return list<array{file_id:int,file_name:string,mime_type:string,file_size:int,version_no:int,uploaded_at:string,uploaded_by_name:string}>
     */
    public static function versionsForSubmission(int $submissionId): array
    {
        $stmt = self::pdo()->prepare(
            "SELECT f.file_id, f.file_name, f.mime_type, f.file_size, f.version_no, f.uploaded_at,
                    CONCAT(u.first_name, ' ', u.last_name) AS uploaded_by_name
             FROM document_files f
             INNER JOIN users u ON u.user_id = f.uploaded_by
             WHERE f.submission_id = :id
             ORDER BY f.version_no DESC"
        );
        $stmt->execute([':id' => $submissionId]);

        return $stmt->fetchAll();
    }

    /**
     * A Faculty user's OWN uploaded files matching a search term — the second
     * Faculty group of the role-aware header search (FR-37).
     *
     * Reached through the owning submission, with `s.faculty_id` bound from
     * the session: the ownership test is part of the SQL, so no crafted query
     * string can surface another faculty member's filename. Mirrors
     * findWithOwner(), which the download route uses for the same reason.
     *
     * Every version, not just the current one — a faculty member searching for
     * a filename they uploaded should find it even after a resubmission
     * superseded it. The row carries its submission_id so the view can link to
     * the document-detail screen (FR-11), which enforces ownership again.
     *
     * @return list<array{file_id:int,file_name:string,version_no:int,uploaded_at:string,submission_id:int,status:string,title:string,doc_type_name:string}>
     */
    public static function searchOwned(int $facultyId, string $term, int $limit): array
    {
        $stmt = self::pdo()->prepare(
            "SELECT f.file_id, f.file_name, f.version_no, f.uploaded_at,
                    s.submission_id, s.status,
                    r.title,
                    dt.name AS doc_type_name
             FROM document_files f
             INNER JOIN submissions s ON s.submission_id = f.submission_id
             INNER JOIN requirements r ON r.requirement_id = s.requirement_id
             INNER JOIN document_types dt ON dt.doc_type_id = r.doc_type_id
             WHERE s.faculty_id = :faculty_id
               AND f.file_name LIKE :term" . Database::likeEscapeClause() . "
             ORDER BY f.uploaded_at DESC, f.file_id DESC
             LIMIT " . (int) $limit
        );
        $stmt->execute([
            ':faculty_id' => $facultyId,
            ':term'       => Database::likePattern($term),
        ]);

        return $stmt->fetchAll();
    }

    private static function pdo(): PDO
    {
        static $config = null;
        if ($config === null) {
            $config = require dirname(__DIR__, 2) . '/config/config.php';
        }

        return Database::connection($config['db']);
    }

    /**
     * One file with everything the in-app viewer screen shows around it
     * (FR-41): who it belongs to, what it answers, and which version it is.
     *
     * Separate from findWithOwner() rather than widening it: that one backs the
     * download and inline routes, which are hot paths that need the ownership
     * columns and nothing else. This one is for a single screen render.
     *
     * @return array{file_id:int,submission_id:int,file_name:string,file_path:string,mime_type:string,file_size:int,version_no:int,uploaded_at:string,faculty_id:int,faculty_name:string,status:string,current_version:int,title:string,doc_type_name:string,deadline:?string,period_label:?string,school_year:string,semester:string}|null
     */
    public static function findForViewer(int $fileId): ?array
    {
        $stmt = self::pdo()->prepare(
            "SELECT f.file_id, f.submission_id, f.file_name, f.file_path, f.mime_type,
                    f.file_size, f.version_no, f.uploaded_at,
                    s.faculty_id, s.status, s.current_version,
                    CONCAT(u.first_name, ' ', u.last_name) AS faculty_name,
                    r.title, r.deadline,
                    dt.name AS doc_type_name,
                    p.label AS period_label, p.school_year, p.semester
             FROM document_files f
             INNER JOIN submissions s      ON s.submission_id = f.submission_id
             INNER JOIN users u            ON u.user_id = s.faculty_id
             INNER JOIN requirements r     ON r.requirement_id = s.requirement_id
             INNER JOIN document_types dt  ON dt.doc_type_id = r.doc_type_id
             INNER JOIN academic_periods p ON p.period_id = r.period_id
             WHERE f.file_id = :id
             LIMIT 1"
        );
        $stmt->execute([':id' => $fileId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }
}
