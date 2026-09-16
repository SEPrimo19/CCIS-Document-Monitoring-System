<?php

declare(strict_types=1);

/**
 * Incremental migration — Batch A: requirement audience targeting (FR-35),
 * Secretary-managed programs (FR-36), and the submission-status rename
 * Returned-for-revision -> Revised.
 *
 * NON-DESTRUCTIVE, and deliberately so. scripts/migrate.php drops and recreates
 * all tables; this script only ALTERs, CREATEs and UPDATEs, so an installation
 * with real submissions, uploaded files and review history keeps every one of
 * them. Use this on any database you care about; use migrate.php only on one
 * you are happy to lose.
 *
 * Usage:  php scripts/migrate_batch_a.php
 *
 * IDEMPOTENT: every step asks information_schema whether it has already been
 * applied and skips itself if so, so running this twice is a no-op the second
 * time. That matters because there is no migrations table to record what ran —
 * the checks ARE the record.
 *
 * On transactions: MySQL commits implicitly before and after every DDL
 * statement, so a CREATE/ALTER cannot be rolled back and wrapping the whole
 * script in one transaction would be a comforting lie. What CAN be atomic is
 * the data movement, so the two steps that only touch rows — the program
 * backfill and the status rename — each run inside their own explicit
 * transaction. The DDL steps are ordered so that a failure part-way leaves the
 * database in a state the next run can pick up from.
 *
 * Refuses to run outside development, matching migrate.php's guard. Note
 * APP_ENV defaults to production when unset (see config/config.php) — the safe
 * direction.
 */

require dirname(__DIR__) . '/config/env.php';
$config = require dirname(__DIR__) . '/config/config.php';
$db = $config['db'];

if ($config['app']['env'] !== 'development') {
    fwrite(STDERR, "REFUSED: APP_ENV is '{$config['app']['env']}', not 'development'.\n");
    fwrite(STDERR, "Database: {$db['name']} on {$db['host']}:{$db['port']} as {$db['user']}.\n");
    fwrite(STDERR, "This script alters a live schema; run it deliberately, from a development config.\n");
    exit(1);
}

$stepNo = 0;

function step(string $message): void
{
    global $stepNo;
    $stepNo++;
    echo sprintf("[%d] %s\n", $stepNo, $message);
}

function done(string $message): void
{
    echo "      -> {$message}\n";
}

function skip(string $message): void
{
    echo "      -> SKIP ({$message})\n";
}

function tableExists(PDO $pdo, string $schema, string $table): bool
{
    $stmt = $pdo->prepare(
        'SELECT 1 FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = :schema AND TABLE_NAME = :table
         LIMIT 1'
    );
    $stmt->execute([':schema' => $schema, ':table' => $table]);

    return $stmt->fetchColumn() !== false;
}

function columnExists(PDO $pdo, string $schema, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = :schema AND TABLE_NAME = :table AND COLUMN_NAME = :column
         LIMIT 1'
    );
    $stmt->execute([':schema' => $schema, ':table' => $table, ':column' => $column]);

    return $stmt->fetchColumn() !== false;
}

function constraintExists(PDO $pdo, string $schema, string $table, string $constraint): bool
{
    $stmt = $pdo->prepare(
        'SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = :schema AND TABLE_NAME = :table AND CONSTRAINT_NAME = :constraint
         LIMIT 1'
    );
    $stmt->execute([':schema' => $schema, ':table' => $table, ':constraint' => $constraint]);

    return $stmt->fetchColumn() !== false;
}

/** The COLUMN_TYPE string of a column, e.g. "enum('Pending','Submitted')". */
function columnType(PDO $pdo, string $schema, string $table, string $column): ?string
{
    $stmt = $pdo->prepare(
        'SELECT COLUMN_TYPE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = :schema AND TABLE_NAME = :table AND COLUMN_NAME = :column
         LIMIT 1'
    );
    $stmt->execute([':schema' => $schema, ':table' => $table, ':column' => $column]);
    $type = $stmt->fetchColumn();

    return $type === false ? null : (string) $type;
}

try {
    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $db['host'], $db['port'], $db['name'], $db['charset']);
    $pdo = new PDO($dsn, $db['user'], $db['pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    $schema = (string) $db['name'];

    echo "CCIS-DMS — Batch A incremental migration\n";
    echo "Database: {$schema} on {$db['host']}:{$db['port']}\n";
    echo str_repeat('-', 72) . "\n";

    /* ----------------------------------------------------------------- 1 --
     * programs: the reference table users.program_id and
     * requirements.target_program_id both point at, so it has to exist first.
     */
    step('CREATE TABLE programs (FR-36)');
    if (tableExists($pdo, $schema, 'programs')) {
        skip('table already exists');
    } else {
        $pdo->exec(
            "CREATE TABLE programs (
                program_id INT AUTO_INCREMENT PRIMARY KEY,
                code       VARCHAR(20) NOT NULL,
                name       VARCHAR(80) NOT NULL,
                is_active  TINYINT(1)  NOT NULL DEFAULT 1,
                created_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uk_programs_code (code)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        done('created');
    }

    /* ----------------------------------------------------------------- 2 --
     * Seed the four programs. INSERT IGNORE against the unique code, so a
     * re-run adds nothing and an operator-added fifth program is left alone.
     */
    step('Seed the four CCIS programs');
    $seed = [
        ['BSIT', 'Bachelor of Science in Information Technology'],
        ['BSIS', 'Bachelor of Science in Information Systems'],
        ['BSCS', 'Bachelor of Science in Computer Science'],
        ['EMC',  'Entertainment and Multimedia Computing'],
    ];
    $insertProgram = $pdo->prepare(
        'INSERT IGNORE INTO programs (code, name, is_active) VALUES (:code, :name, 1)'
    );
    $seeded = 0;
    foreach ($seed as [$code, $name]) {
        $insertProgram->execute([':code' => $code, ':name' => $name]);
        $seeded += $insertProgram->rowCount();
    }
    $seeded > 0 ? done("{$seeded} program(s) inserted") : skip('all four already present');

    /* ----------------------------------------------------------------- 3 --
     * users.program_id + its FK. Added BEFORE the backfill, obviously, and
     * before program_dept is dropped so step 4 still has something to read.
     */
    step('ADD COLUMN users.program_id + FK -> programs');
    if (columnExists($pdo, $schema, 'users', 'program_id')) {
        skip('column already exists');
    } else {
        $pdo->exec('ALTER TABLE users ADD COLUMN program_id INT DEFAULT NULL AFTER password_hash');
        $pdo->exec('ALTER TABLE users ADD KEY idx_users_program (program_id)');
        done('column + index added');
    }
    if (constraintExists($pdo, $schema, 'users', 'fk_users_program')) {
        skip('fk_users_program already exists');
    } else {
        $pdo->exec(
            'ALTER TABLE users
             ADD CONSTRAINT fk_users_program FOREIGN KEY (program_id)
             REFERENCES programs(program_id) ON DELETE SET NULL'
        );
        done('fk_users_program added');
    }

    /* ----------------------------------------------------------------- 4 --
     * Backfill program_id from the old free-text program_dept, matching the
     * code case-insensitively. Values that are not a program code — 'CCIS' is
     * the College itself, and the Secretary is not in a program — match
     * nothing and correctly stay NULL. Pure DML, so it gets a real transaction.
     */
    step('Backfill users.program_id from users.program_dept');
    if (!columnExists($pdo, $schema, 'users', 'program_dept')) {
        skip('program_dept already dropped — nothing to backfill from');
    } else {
        $pdo->beginTransaction();
        try {
            $backfill = $pdo->prepare(
                "UPDATE users u
                 INNER JOIN programs p ON LOWER(TRIM(p.code)) = LOWER(TRIM(u.program_dept))
                 SET u.program_id = p.program_id
                 WHERE u.program_id IS NULL
                   AND u.program_dept IS NOT NULL
                   AND TRIM(u.program_dept) <> ''"
            );
            $backfill->execute();
            $matched = $backfill->rowCount();

            $unmatched = $pdo->query(
                "SELECT COUNT(*) FROM users
                 WHERE program_id IS NULL
                   AND program_dept IS NOT NULL
                   AND TRIM(program_dept) <> ''"
            )->fetchColumn();

            $pdo->commit();
            done("{$matched} user(s) matched to a program; {$unmatched} left NULL (not a program code)");
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /* ----------------------------------------------------------------- 5 --
     * Drop the free-text column. program_id replaces it outright: nothing in
     * the application reads program_dept any more, and keeping it would leave
     * a second, self-editable copy of the same fact.
     */
    step('DROP COLUMN users.program_dept');
    if (!columnExists($pdo, $schema, 'users', 'program_dept')) {
        skip('column already dropped');
    } else {
        $pdo->exec('ALTER TABLE users DROP COLUMN program_dept');
        done('dropped');
    }

    /* ----------------------------------------------------------------- 6 --
     * requirements.target_program_id — the payload of a 'program' audience.
     */
    step('ADD COLUMN requirements.target_program_id + FK -> programs (FR-35)');
    if (columnExists($pdo, $schema, 'requirements', 'target_program_id')) {
        skip('column already exists');
    } else {
        $pdo->exec('ALTER TABLE requirements ADD COLUMN target_program_id INT DEFAULT NULL AFTER applies_to');
        $pdo->exec('ALTER TABLE requirements ADD KEY idx_req_target_program (target_program_id)');
        done('column + index added');
    }
    if (constraintExists($pdo, $schema, 'requirements', 'fk_req_program')) {
        skip('fk_req_program already exists');
    } else {
        $pdo->exec(
            'ALTER TABLE requirements
             ADD CONSTRAINT fk_req_program FOREIGN KEY (target_program_id)
             REFERENCES programs(program_id)'
        );
        done('fk_req_program added');
    }

    /* ----------------------------------------------------------------- 7 --
     * requirement_targets — the payload of an 'individual' audience.
     */
    step('CREATE TABLE requirement_targets (FR-35)');
    if (tableExists($pdo, $schema, 'requirement_targets')) {
        skip('table already exists');
    } else {
        $pdo->exec(
            "CREATE TABLE requirement_targets (
                requirement_id INT NOT NULL,
                faculty_id     INT NOT NULL,
                PRIMARY KEY (requirement_id, faculty_id),
                KEY idx_reqtarget_faculty (faculty_id),
                CONSTRAINT fk_reqtarget_req     FOREIGN KEY (requirement_id) REFERENCES requirements(requirement_id) ON DELETE CASCADE,
                CONSTRAINT fk_reqtarget_faculty FOREIGN KEY (faculty_id)     REFERENCES users(user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        done('created');
    }

    /* ----------------------------------------------------------------- 8 --
     * submissions.status: Returned-for-revision -> Revised, in the only order
     * that never strands a row on a value its own column does not allow:
     *
     *   (a) WIDEN the enum to accept BOTH names. No row changes; every
     *       existing value is still legal.
     *   (b) UPDATE the rows across to the new name, in a transaction.
     *   (c) NARROW the enum to drop the old name. Safe now that (b) left
     *       nothing on it — doing this first would silently coerce every
     *       Returned-for-revision row to '' instead.
     *
     * Each ALTER is checked against the live COLUMN_TYPE, so a re-run of a
     * partially-applied migration resumes at the right point.
     */
    step("submissions.status: 'Returned-for-revision' -> 'Revised'");
    $statusType = columnType($pdo, $schema, 'submissions', 'status');
    if ($statusType === null) {
        throw new RuntimeException('submissions.status not found — is this the CCIS-DMS schema?');
    }

    if (str_contains($statusType, "'Returned-for-revision'")) {
        // (a) widen
        if (!str_contains($statusType, "'Revised'")) {
            $pdo->exec(
                "ALTER TABLE submissions
                 MODIFY COLUMN status ENUM('Pending','Submitted','Approved','Returned-for-revision','Revised')
                 NOT NULL DEFAULT 'Pending'"
            );
            done('enum widened to accept both names');
        } else {
            done('enum already accepts both names');
        }

        // (b) move the rows
        $pdo->beginTransaction();
        try {
            $moved = $pdo->exec("UPDATE submissions SET status = 'Revised' WHERE status = 'Returned-for-revision'");
            $pdo->commit();
            done("{$moved} submission row(s) renamed to 'Revised'");
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        // (c) narrow
        $pdo->exec(
            "ALTER TABLE submissions
             MODIFY COLUMN status ENUM('Pending','Submitted','Approved','Revised')
             NOT NULL DEFAULT 'Pending'"
        );
        done("enum narrowed to ('Pending','Submitted','Approved','Revised')");
    } else {
        skip('enum already renamed');
    }

    /* ----------------------------------------------------------------- 9 --
     * reviews.decision carries the SAME string as submissions.status — one
     * $decision value in ReviewerController::decide() is written to both — so
     * it has to be renamed in lockstep or every future "return for revision"
     * would fail on the reviews INSERT. Same three-phase order.
     */
    step("reviews.decision: 'Returned-for-revision' -> 'Revised'");
    $decisionType = columnType($pdo, $schema, 'reviews', 'decision');
    if ($decisionType === null) {
        throw new RuntimeException('reviews.decision not found — is this the CCIS-DMS schema?');
    }

    if (str_contains($decisionType, "'Returned-for-revision'")) {
        if (!str_contains($decisionType, "'Revised'")) {
            $pdo->exec(
                "ALTER TABLE reviews
                 MODIFY COLUMN decision ENUM('Approved','Returned-for-revision','Revised') NOT NULL"
            );
            done('enum widened to accept both names');
        } else {
            done('enum already accepts both names');
        }

        $pdo->beginTransaction();
        try {
            $moved = $pdo->exec("UPDATE reviews SET decision = 'Revised' WHERE decision = 'Returned-for-revision'");
            $pdo->commit();
            done("{$moved} review row(s) renamed to 'Revised'");
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $pdo->exec("ALTER TABLE reviews MODIFY COLUMN decision ENUM('Approved','Revised') NOT NULL");
        done("enum narrowed to ('Approved','Revised')");
    } else {
        skip('enum already renamed');
    }

    /* ---------------------------------------------------------------- 10 --
     * Report what survived, so the operator can see at a glance that this was
     * not a rebuild.
     */
    step('Verify');
    $counts = [
        'users'              => (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(),
        'programs'           => (int) $pdo->query('SELECT COUNT(*) FROM programs')->fetchColumn(),
        'requirements'       => (int) $pdo->query('SELECT COUNT(*) FROM requirements')->fetchColumn(),
        'requirement_targets'=> (int) $pdo->query('SELECT COUNT(*) FROM requirement_targets')->fetchColumn(),
        'submissions'        => (int) $pdo->query('SELECT COUNT(*) FROM submissions')->fetchColumn(),
        'document_files'     => (int) $pdo->query('SELECT COUNT(*) FROM document_files')->fetchColumn(),
        'reviews'            => (int) $pdo->query('SELECT COUNT(*) FROM reviews')->fetchColumn(),
    ];
    foreach ($counts as $table => $count) {
        done(sprintf('%-20s %d row(s)', $table, $count));
    }

    echo str_repeat('-', 72) . "\n";
    echo "DONE. Batch A applied. Re-running this script is safe — every step is idempotent.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "\nBatch A migration FAILED: {$e->getMessage()}\n");
    fwrite(STDERR, "Nothing after the failing step ran. Fix the cause and re-run — completed steps skip themselves.\n");
    exit(1);
}
