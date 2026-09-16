<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

/**
 * document_types table access for administrator document-type management (FR-27).
 * Deactivation is a soft-delete via is_active — rows are never removed, since
 * requirements elsewhere reference doc_type_id.
 */
final class DocumentType
{
    /**
     * Every document type, name-ordered, for the admin list.
     *
     * @return list<array{doc_type_id:int,name:string,description:?string,is_active:int,created_by:?int,created_at:string}>
     */
    public static function all(): array
    {
        $stmt = self::pdo()->query(
            'SELECT doc_type_id, name, description, is_active, created_by, created_at
             FROM document_types
             ORDER BY name ASC'
        );

        return $stmt->fetchAll();
    }

    /**
     * @return array{doc_type_id:int,name:string,description:?string,is_active:int,created_by:?int,created_at:string}|null
     */
    public static function find(int $id): ?array
    {
        $stmt = self::pdo()->prepare(
            'SELECT doc_type_id, name, description, is_active, created_by, created_at
             FROM document_types
             WHERE doc_type_id = :id
             LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Case-insensitive uniqueness check, optionally excluding the row being edited.
     */
    public static function existsByName(string $name, ?int $exceptId = null): bool
    {
        $sql = 'SELECT 1 FROM document_types WHERE LOWER(name) = LOWER(:name)';
        $params = [':name' => $name];

        if ($exceptId !== null) {
            $sql .= ' AND doc_type_id <> :except_id';
            $params[':except_id'] = $exceptId;
        }

        $sql .= ' LIMIT 1';

        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetch() !== false;
    }

    /**
     * Insert a new, active document type. Returns the new doc_type_id.
     */
    public static function create(string $name, ?string $description, int $createdBy): int
    {
        $stmt = self::pdo()->prepare(
            'INSERT INTO document_types (name, description, is_active, created_by)
             VALUES (:name, :description, 1, :created_by)'
        );
        $stmt->execute([
            ':name'        => $name,
            ':description' => $description,
            ':created_by'  => $createdBy,
        ]);

        return (int) self::pdo()->lastInsertId();
    }

    public static function update(int $id, string $name, ?string $description): void
    {
        $stmt = self::pdo()->prepare(
            'UPDATE document_types
             SET name = :name, description = :description
             WHERE doc_type_id = :id'
        );
        $stmt->execute([
            ':name'        => $name,
            ':description' => $description,
            ':id'          => $id,
        ]);
    }

    public static function setActive(int $id, bool $active): void
    {
        $stmt = self::pdo()->prepare(
            'UPDATE document_types SET is_active = :is_active WHERE doc_type_id = :id'
        );
        $stmt->execute([
            ':is_active' => $active ? 1 : 0,
            ':id'        => $id,
        ]);
    }

    /**
     * Document types whose name or description matches a search term — the
     * Secretary's half of the role-aware header search (FR-37).
     *
     * Inactive types are included (like all()): a type is deactivated, never
     * deleted, and the requirements already published against it still exist.
     * `is_active` comes back so the view can label a retired one.
     *
     * Two comparisons, two distinct placeholders bound to the same pattern:
     * PDO with ATTR_EMULATE_PREPARES=false rewrites named placeholders to
     * positional ones and rejects the same name appearing twice.
     *
     * @return list<array{doc_type_id:int,name:string,description:?string,is_active:int}>
     */
    public static function search(string $term, int $limit): array
    {
        $escape = Database::likeEscapeClause();

        $stmt = self::pdo()->prepare(
            "SELECT doc_type_id, name, description, is_active
             FROM document_types
             WHERE name LIKE :name{$escape} OR description LIKE :description{$escape}
             ORDER BY name ASC
             LIMIT " . (int) $limit
        );

        $pattern = Database::likePattern($term);
        $stmt->execute([':name' => $pattern, ':description' => $pattern]);

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
}
