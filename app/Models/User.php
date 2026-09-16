<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

/**
 * users table access. Read-only finders needed by Auth/Guard sit alongside the
 * Admin user-management CRUD (create/edit/deactivate/reactivate, role and
 * program assignment — FR-26, FR-36). Deactivation is a soft-delete via
 * status, never a row delete, since audit_log and submissions reference
 * user_id.
 *
 * Note the split between updateProfile() (Secretary-side; sets role_id and
 * program_id) and updateOwnProfile() (self-service; sets neither). Both
 * columns decide what the system expects OF a user — their privileges and,
 * since FR-35, which requirements target them — so neither is self-editable.
 */
final class User
{
    /**
     * Every user account — active AND inactive, since accreditation reports
     * need historical accounts — joined to role name, for the admin
     * user-management list. Active accounts sort first, then alphabetically
     * by name.
     *
     * @return list<array{user_id:int,first_name:string,last_name:string,email:string,role_id:int,role_name:string,program_code:?string,status:string,created_at:string}>
     */
    public static function all(): array
    {
        $stmt = self::pdo()->query(
            "SELECT u.user_id, u.first_name, u.last_name, u.email, u.role_id, r.role_name,
                    p.code AS program_code, u.status, u.created_at
             FROM users u
             INNER JOIN roles r ON r.role_id = u.role_id
             LEFT JOIN programs p ON p.program_id = u.program_id
             ORDER BY (u.status = 'active') DESC, u.last_name ASC, u.first_name ASC"
        );

        return $stmt->fetchAll();
    }

    /**
     * One user, with role_id/role_name, for the edit form.
     *
     * @return array{user_id:int,first_name:string,last_name:string,email:string,role_id:int,role_name:string,program_id:?int,status:string}|null
     */
    public static function find(int $id): ?array
    {
        $stmt = self::pdo()->prepare(
            'SELECT u.user_id, u.first_name, u.last_name, u.email, u.role_id, r.role_name, u.program_id, u.status
             FROM users u
             INNER JOIN roles r ON r.role_id = u.role_id
             WHERE u.user_id = :id
             LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Insert a new, active user account. Returns the new user_id. The caller
     * is responsible for normalizing the email (Auth::normalizeEmail) and
     * hashing the password (password_hash(..., PASSWORD_BCRYPT)) — this
     * method never receives or stores a plaintext password.
     */
    public static function create(string $firstName, string $lastName, string $email, int $roleId, string $passwordHash, ?int $programId): int
    {
        $stmt = self::pdo()->prepare(
            "INSERT INTO users (role_id, first_name, last_name, email, password_hash, program_id, status)
             VALUES (:role_id, :first_name, :last_name, :email, :password_hash, :program_id, 'active')"
        );
        $stmt->execute([
            ':role_id'       => $roleId,
            ':first_name'    => $firstName,
            ':last_name'     => $lastName,
            ':email'         => $email,
            ':password_hash' => $passwordHash,
            ':program_id'    => $programId,
        ]);

        return (int) self::pdo()->lastInsertId();
    }

    /**
     * Update a user's profile fields (name, email, role, program). Password
     * changes go through updatePasswordHash() instead, since edit leaves the
     * password untouched unless the admin supplies a new one.
     *
     * program_id lives here and deliberately NOT in updateOwnProfile():
     * requirement audiences key off it (FR-35), so only the Secretary may set
     * it — a faculty member who could edit their own program could edit their
     * way out of a requirement targeted at it.
     */
    public static function updateProfile(int $id, string $firstName, string $lastName, string $email, int $roleId, ?int $programId): void
    {
        $stmt = self::pdo()->prepare(
            'UPDATE users
             SET first_name = :first_name, last_name = :last_name, email = :email,
                 role_id = :role_id, program_id = :program_id
             WHERE user_id = :id'
        );
        $stmt->execute([
            ':first_name' => $firstName,
            ':last_name'  => $lastName,
            ':email'      => $email,
            ':role_id'    => $roleId,
            ':program_id' => $programId,
            ':id'         => $id,
        ]);
    }

    /**
     * Soft-delete: flips status active/inactive. A deactivated account is
     * rejected immediately by Guard::requireAuth() on its next request (and by
     * Auth::attempt() on its next login attempt) — never a row delete.
     */
    public static function setActive(int $id, bool $active): void
    {
        $stmt = self::pdo()->prepare('UPDATE users SET status = :status WHERE user_id = :id');
        $stmt->execute([
            ':status' => $active ? 'active' : 'inactive',
            ':id'     => $id,
        ]);
    }

    /**
     * Case-insensitive uniqueness check (email is already normalized to
     * lowercase before being stored, but this stays defensive regardless),
     * optionally excluding the row being edited.
     */
    public static function existsByEmail(string $email, ?int $exceptId = null): bool
    {
        $sql = 'SELECT 1 FROM users WHERE LOWER(email) = LOWER(:email)';
        $params = [':email' => $email];

        if ($exceptId !== null) {
            $sql .= ' AND user_id <> :except_id';
            $params[':except_id'] = $exceptId;
        }

        $sql .= ' LIMIT 1';

        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetch() !== false;
    }

    /**
     * Count of active users whose role is Secretary. Used to block deactivating
     * or demoting the last remaining active Secretary — that account is the
     * only one that can manage users, so losing it would lock the college out
     * of its own system with no way back in short of editing the database.
     */
    public static function activeAdminCount(): int
    {
        $stmt = self::pdo()->query(
            "SELECT COUNT(*) FROM users u
             INNER JOIN roles r ON r.role_id = u.role_id
             WHERE u.status = 'active' AND r.role_name = 'Secretary'"
        );

        return (int) $stmt->fetchColumn();
    }

    /**
     * Atomically deactivate a user while preserving the last-admin invariant.
     * A single conditional statement flips status -> inactive, but for an
     * Secretary only when another active Secretary still remains, so two
     * Secretaries deactivating each other in parallel cannot both pass a stale
     * count and leave the system with zero admins (TOCTOU-safe — the plain
     * activeAdminCount() pre-check in the controller can be raced; this cannot).
     * The active-admin count reads `users` through a derived table because
     * MySQL forbids referencing the table being updated directly. Returns true
     * if the row was deactivated, false if the guard refused it (last admin).
     */
    public static function deactivateGuardingLastAdmin(int $id): bool
    {
        $stmt = self::pdo()->prepare(
            "UPDATE users AS target
             INNER JOIN roles AS tr ON tr.role_id = target.role_id
             SET target.status = 'inactive'
             WHERE target.user_id = :id
               AND target.status = 'active'
               AND (
                    tr.role_name <> 'Secretary'
                    OR (
                        SELECT COUNT(*) FROM (
                            SELECT u2.user_id
                            FROM users u2
                            INNER JOIN roles r2 ON r2.role_id = u2.role_id
                            WHERE u2.status = 'active' AND r2.role_name = 'Secretary'
                        ) AS active_admins
                    ) > 1
               )"
        );
        $stmt->execute([':id' => $id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Update name/email/role. When $guardLastAdmin is true (an active
     * Secretary is being demoted by someone else), the change runs inside a
     * transaction that locks and re-counts the active Secretaries with
     * SELECT ... FOR UPDATE, refusing the demotion if it would drop the count
     * below one — closing the TOCTOU window the controller's plain pre-check
     * leaves open. Returns false only when a demotion was refused for that
     * reason; every other update returns true.
     */
    public static function updateProfileGuardingLastAdmin(int $id, string $firstName, string $lastName, string $email, int $roleId, ?int $programId, bool $guardLastAdmin): bool
    {
        if (!$guardLastAdmin) {
            self::updateProfile($id, $firstName, $lastName, $email, $roleId, $programId);

            return true;
        }

        $pdo = self::pdo();
        $pdo->beginTransaction();

        try {
            $count = (int) $pdo->query(
                "SELECT COUNT(*) FROM users u
                 INNER JOIN roles r ON r.role_id = u.role_id
                 WHERE u.status = 'active' AND r.role_name = 'Secretary'
                 FOR UPDATE"
            )->fetchColumn();

            if ($count <= 1) {
                $pdo->rollBack();

                return false;
            }

            $stmt = $pdo->prepare(
                'UPDATE users
                 SET first_name = :first_name, last_name = :last_name, email = :email,
                     role_id = :role_id, program_id = :program_id
                 WHERE user_id = :id'
            );
            $stmt->execute([
                ':first_name' => $firstName,
                ':last_name'  => $lastName,
                ':email'      => $email,
                ':role_id'    => $roleId,
                ':program_id' => $programId,
                ':id'         => $id,
            ]);

            $pdo->commit();

            return true;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }
    }

    /**
     * Look up an active user by email, including their role name, for login.
     *
     * @return array{user_id:int,first_name:string,last_name:string,email:string,password_hash:string,role_name:string}|null
     */
    public static function findActiveByEmail(string $email): ?array
    {
        $stmt = self::pdo()->prepare(
            'SELECT u.user_id, u.first_name, u.last_name, u.email, u.password_hash, r.role_name
             FROM users u
             INNER JOIN roles r ON r.role_id = u.role_id
             WHERE u.email = :email AND u.status = \'active\'
             LIMIT 1'
        );
        $stmt->execute([':email' => $email]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @return array{user_id:int,first_name:string,last_name:string,email:string,status:string,avatar_path:?string,role_name:string}|null
     */
    public static function findById(int $userId): ?array
    {
        $stmt = self::pdo()->prepare(
            'SELECT u.user_id, u.first_name, u.last_name, u.email, u.status, u.avatar_path, r.role_name
             FROM users u
             INNER JOIN roles r ON r.role_id = u.role_id
             WHERE u.user_id = :id
             LIMIT 1'
        );
        $stmt->execute([':id' => $userId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * The signed-in user's own record for the profile screen (FR-5), including
     * the password hash so a password change can verify the CURRENT password
     * before accepting a new one.
     *
     * Separate from find() on purpose: find() feeds the admin user-management
     * screens and deliberately never selects password_hash. Only the
     * self-service password change needs it, so only this method exposes it.
     *
     * program_code/program_name are joined in for DISPLAY ONLY — the profile
     * screen shows the user which program the Secretary assigned them, but the
     * form has no control for it (FR-36); see updateOwnProfile().
     *
     * @return array{user_id:int,employee_no:?string,first_name:string,last_name:string,email:string,program_code:?string,program_name:?string,password_hash:string,role_name:string}|null
     */
    public static function profileFor(int $userId): ?array
    {
        $stmt = self::pdo()->prepare(
            'SELECT u.user_id, u.employee_no, u.first_name, u.last_name, u.email, u.avatar_path,
                    p.code AS program_code, p.name AS program_name,
                    u.password_hash, r.role_name
             FROM users u
             INNER JOIN roles r ON r.role_id = u.role_id
             LEFT JOIN programs p ON p.program_id = u.program_id
             WHERE u.user_id = :id
             LIMIT 1'
        );
        $stmt->execute([':id' => $userId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Update the fields a user may change about THEMSELVES (FR-5).
     *
     * Note what is absent: role_id, status — and, since FR-35, program_id.
     * Self-service editing must never be a privilege-escalation path, so those
     * columns are simply not in this statement — a forged role_id or
     * program_id in the request has nothing to bind to, rather than relying on
     * the controller remembering to strip it.
     *
     * program_id left this statement when requirement audiences started keying
     * off it: a faculty member who could set their own program could move
     * themselves out of a requirement targeted at that program, which is an
     * obligation-evasion path, not a profile preference. It is assigned by the
     * Secretary on the user form instead (see updateProfile()).
     */
    public static function updateOwnProfile(int $id, string $firstName, string $lastName, string $email): void
    {
        $stmt = self::pdo()->prepare(
            'UPDATE users
                SET first_name = :first_name, last_name = :last_name, email = :email
              WHERE user_id = :id'
        );
        $stmt->execute([
            ':first_name' => $firstName,
            ':last_name'  => $lastName,
            ':email'      => $email,
            ':id'         => $id,
        ]);
    }

    /**
     * user_id list of every active Secretary — the recipients of the "new
     * submission awaiting review" notification (FR-21). The review queue is
     * shared rather than assigned, so if the college ever staffs more than one
     * Secretary they are all told, not one nominated individual.
     *
     * @return list<int>
     */
    public static function activeSecretaryIds(): array
    {
        $stmt = self::pdo()->query(
            "SELECT u.user_id
             FROM users u
             INNER JOIN roles r ON r.role_id = u.role_id
             WHERE u.status = 'active' AND r.role_name = 'Secretary'"
        );

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * user_id list of every active user whose role is Faculty. Used to
     * eagerly generate one Pending submission per faculty when an
     * administrator publishes a requirement (FR-28).
     *
     * @return list<int>
     */
    public static function activeFacultyIds(): array
    {
        $stmt = self::pdo()->query(
            "SELECT u.user_id
             FROM users u
             INNER JOIN roles r ON r.role_id = u.role_id
             WHERE u.status = 'active' AND r.role_name = 'Faculty'"
        );

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * user_id list of every active Faculty account assigned to one program —
     * the audience of a requirement published with applies_to='program'
     * (FR-35). Role and status are re-checked here, in SQL, rather than
     * trusted from the form.
     *
     * @return list<int>
     */
    public static function activeFacultyIdsForProgram(int $programId): array
    {
        $stmt = self::pdo()->prepare(
            "SELECT u.user_id
             FROM users u
             INNER JOIN roles r ON r.role_id = u.role_id
             WHERE u.status = 'active' AND r.role_name = 'Faculty' AND u.program_id = :program_id"
        );
        $stmt->execute([':program_id' => $programId]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Narrow a list of posted user ids down to the ones that really are active
     * Faculty accounts — the audience of a requirement published with
     * applies_to='individual' (FR-35).
     *
     * The posted ids are NEVER trusted: this re-derives the answer from the
     * users/roles tables, so a hand-crafted POST naming the Secretary, an
     * inactive account, or an id that does not exist simply loses those ids
     * here rather than publishing a submission row against them. The ids are
     * cast to int and bound positionally (the IN list is built from
     * placeholders, never from the values) so the widened list is still a
     * prepared statement.
     *
     * @param list<int> $userIds
     * @return list<int> the subset that is active + Faculty, de-duplicated
     */
    public static function filterActiveFacultyIds(array $userIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $userIds)));
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = self::pdo()->prepare(
            "SELECT u.user_id
             FROM users u
             INNER JOIN roles r ON r.role_id = u.role_id
             WHERE u.status = 'active' AND r.role_name = 'Faculty'
               AND u.user_id IN ({$placeholders})"
        );
        $stmt->execute($ids);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * {user_id, first_name, last_name, program_code} for every active Faculty
     * account — the checkbox list behind the requirement form's "Specific
     * faculty" audience (FR-35). The program code is shown alongside the name
     * so the Secretary can tell two similarly-named staff apart.
     *
     * @return list<array{user_id:int,first_name:string,last_name:string,program_code:?string}>
     */
    public static function activeFacultyForPicker(): array
    {
        $stmt = self::pdo()->query(
            "SELECT u.user_id, u.first_name, u.last_name, p.code AS program_code
             FROM users u
             INNER JOIN roles r ON r.role_id = u.role_id
             LEFT JOIN programs p ON p.program_id = u.program_id
             WHERE u.status = 'active' AND r.role_name = 'Faculty'
             ORDER BY u.last_name ASC, u.first_name ASC"
        );

        return $stmt->fetchAll();
    }

    /**
     * {user_id, first_name, last_name} for every active Faculty account,
     * ordered by last_name then first_name — the row axis of the admin
     * monitoring matrix (FR-17).
     *
     * @return list<array{user_id:int,first_name:string,last_name:string}>
     */
    public static function activeFaculty(): array
    {
        $stmt = self::pdo()->query(
            "SELECT u.user_id, u.first_name, u.last_name
             FROM users u
             INNER JOIN roles r ON r.role_id = u.role_id
             WHERE u.status = 'active' AND r.role_name = 'Faculty'
             ORDER BY u.last_name ASC, u.first_name ASC"
        );

        return $stmt->fetchAll();
    }

    /**
     * {user_id, first_name, last_name} for the monitoring matrix's row axis
     * (FR-17): every Faculty account that is either currently active OR has
     * at least one submission in this period. This lets a faculty member
     * deactivated mid-period keep appearing in the matrix (with their
     * historical submissions) instead of the matrix silently dropping them
     * while other admin figures on the same page still count them — see
     * complianceByFaculty(). Distinct, ordered by last_name then first_name.
     *
     * @return list<array{user_id:int,first_name:string,last_name:string}>
     */
    public static function facultyForPeriod(int $periodId): array
    {
        $stmt = self::pdo()->prepare(
            "SELECT DISTINCT u.user_id, u.first_name, u.last_name
             FROM users u
             INNER JOIN roles r ON r.role_id = u.role_id
             WHERE r.role_name = 'Faculty'
               AND (
                    u.status = 'active'
                    OR EXISTS (
                        SELECT 1 FROM submissions s
                        INNER JOIN requirements req ON req.requirement_id = s.requirement_id
                        WHERE s.faculty_id = u.user_id AND req.period_id = :period_id
                    )
               )
             ORDER BY u.last_name ASC, u.first_name ASC"
        );
        $stmt->execute([':period_id' => $periodId]);

        return $stmt->fetchAll();
    }

    /**
     * Faculty accounts whose name matches a search term — the Secretary's
     * half of the role-aware header search (FR-37).
     *
     * Matched against the joined "first last" rather than the two columns
     * separately: that one comparison already covers a term matching either
     * half, so "Dela" and "Juan Dela Cruz" find the same person. The term
     * arrives as a bound parameter and its LIKE metacharacters are neutralised
     * by Database::likePattern(), so a term of "%" matches a literal percent
     * sign and not every row.
     *
     * Active AND inactive accounts, matching User::all(): a deactivated
     * faculty member's submissions still exist and are still reportable, so a
     * search that silently skipped them would disagree with every other admin
     * screen. The status is returned so the view can say which is which.
     *
     * @return list<array{user_id:int,first_name:string,last_name:string,email:string,status:string,program_code:?string}>
     */
    public static function searchFaculty(string $term, int $limit): array
    {
        $stmt = self::pdo()->prepare(
            "SELECT u.user_id, u.first_name, u.last_name, u.email, u.status,
                    p.code AS program_code
             FROM users u
             INNER JOIN roles ro ON ro.role_id = u.role_id
             LEFT JOIN programs p ON p.program_id = u.program_id
             WHERE ro.role_name = 'Faculty'
               AND CONCAT(u.first_name, ' ', u.last_name) LIKE :term" . Database::likeEscapeClause() . "
             ORDER BY u.last_name ASC, u.first_name ASC
             LIMIT " . (int) $limit
        );
        $stmt->execute([':term' => Database::likePattern($term)]);

        return $stmt->fetchAll();
    }

    public static function touchLastLogin(int $userId): void
    {
        $stmt = self::pdo()->prepare('UPDATE users SET last_login = NOW() WHERE user_id = :id');
        $stmt->execute([':id' => $userId]);
    }

    /**
     * Persist a new password hash, used to opportunistically rehash on login
     * when the stored hash's cost/algorithm is out of date.
     */
    public static function updatePasswordHash(int $userId, string $newHash): void
    {
        $stmt = self::pdo()->prepare('UPDATE users SET password_hash = :hash WHERE user_id = :id');
        $stmt->execute([':hash' => $newHash, ':id' => $userId]);
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
     * The stored filename of a user's profile photo, or null (FR-40).
     *
     * Returns null for an account that does not exist as well as for one with
     * no photo: AvatarController answers both with the same 404, so a signed-in
     * user cannot use the avatar route to discover which user ids are real.
     */
    public static function avatarPathFor(int $userId): ?string
    {
        $stmt = self::pdo()->prepare(
            'SELECT avatar_path FROM users WHERE user_id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $userId]);
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        $path = $row['avatar_path'] ?? null;

        return is_string($path) && $path !== '' ? $path : null;
    }

    /**
     * Point a user at a new profile photo, or clear it with null (FR-40).
     *
     * Only ever called with the SIGNED-IN user's own id — a photo is the one
     * thing on the profile screen a user may change about themselves, because
     * unlike role, status and program it decides nothing about what the system
     * expects of them (contrast FR-36).
     *
     * Returns the filename this row held before the update, so the caller can
     * unlink it. Storage is not transactional: the row is the record of truth,
     * and the caller deletes the old file only after this has committed.
     */
    public static function updateAvatarPath(int $userId, ?string $storedName): ?string
    {
        $previous = self::avatarPathFor($userId);

        $stmt = self::pdo()->prepare(
            'UPDATE users SET avatar_path = :path WHERE user_id = :id'
        );
        $stmt->execute([
            ':path' => $storedName,
            ':id'   => $userId,
        ]);

        return $previous;
    }
}
