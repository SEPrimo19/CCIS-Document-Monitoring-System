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

    private static function pdo(): PDO
    {
        static $config = null;
        if ($config === null) {
            $config = require dirname(__DIR__, 2) . '/config/config.php';
        }

        return Database::connection($config['db']);
    }
}
