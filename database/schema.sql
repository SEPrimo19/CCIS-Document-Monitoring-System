-- ============================================================================
-- CCIS-DMS database schema
-- Northwest Samar State University — CCIS Document Monitoring System
-- Generated from the finalized ERD & Data Dictionary in Design/05-data-model.md
--
-- Locked design inputs (2026-07-20):
--   Roles:          Secretary (manages + verifies), Faculty
--   Workflow:       upload -> review -> approve / mark revised -> recorded
--   Notifications:  in-app only
--   Archiving:      by academic year (via academic_periods.is_active)
--   Audit log:      submit/resubmit/approve/revise + admin config changes (admin view)
--   Review routing: SHARED QUEUE — any Reviewer can act on any Submitted doc
--   Report export:  printable PDF
--   File uploads:   PDF + Word (DOC/DOCX), max 10 MB
--
-- NOTE: destructive rebuild — drops and recreates all tables. Run via scripts/migrate.php.
-- ============================================================================

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS login_attempts;
DROP TABLE IF EXISTS audit_log;
DROP TABLE IF EXISTS notifications;
DROP TABLE IF EXISTS reviews;
DROP TABLE IF EXISTS document_files;
DROP TABLE IF EXISTS submissions;
DROP TABLE IF EXISTS requirement_targets;
DROP TABLE IF EXISTS requirements;
DROP TABLE IF EXISTS academic_periods;
DROP TABLE IF EXISTS document_types;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS programs;
DROP TABLE IF EXISTS roles;
SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE roles (
    role_id     INT AUTO_INCREMENT PRIMARY KEY,
    role_name   VARCHAR(30)  NOT NULL,
    description VARCHAR(255) DEFAULT NULL,
    UNIQUE KEY uk_roles_name (role_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Academic programs of the college (FR-36). Reference data the Secretary
-- maintains, replacing the old free-text users.program_dept: a requirement can
-- now be targeted at a program (FR-35), so the program a faculty member belongs
-- to has to be a controlled value, not typed prose.
CREATE TABLE programs (
    program_id INT AUTO_INCREMENT PRIMARY KEY,
    code       VARCHAR(20) NOT NULL,
    name       VARCHAR(80) NOT NULL,
    is_active  TINYINT(1)  NOT NULL DEFAULT 1,
    created_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_programs_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE users (
    user_id       INT AUTO_INCREMENT PRIMARY KEY,
    role_id       INT          NOT NULL,
    employee_no   VARCHAR(30)  DEFAULT NULL,
    first_name    VARCHAR(60)  NOT NULL,
    last_name     VARCHAR(60)  NOT NULL,
    email         VARCHAR(120) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    -- Secretary-assigned, NOT self-editable (FR-36): requirement audiences key
    -- off this column, so letting a faculty member set their own program would
    -- let them edit their way out of a requirement targeted at it.
    -- NULL for the Secretary, who belongs to the office rather than a program.
    program_id    INT          DEFAULT NULL,
    -- Stored filename of the optional profile photo (FR-40), NULL until someone
    -- uploads one. VARCHAR(120) against a generated name of about 30 characters
    -- (u<id>_<16 hex>.<ext>): room for a longer scheme later, still far short of
    -- anything that could hold a path. The file itself lives outside the web
    -- root in storage/avatars/ and only the name is kept here.
    --
    -- This column was added to running installations by
    -- scripts/migrate_batch_c.php but was not back-ported here until
    -- 2026-09-24, so every fresh install built from this file created a users
    -- table without it, and then answered 500 on every signed-in page:
    --   SQLSTATE[42S22]: Unknown column 'u.avatar_path' in 'field list'
    -- Guard re-reads the account on each request and selects this column, so a
    -- missing one breaks the whole application rather than just the photo.
    avatar_path   VARCHAR(120) DEFAULT NULL,
    status        ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_login    DATETIME     DEFAULT NULL,
    UNIQUE KEY uk_users_email (email),
    UNIQUE KEY uk_users_empno (employee_no),
    KEY idx_users_role (role_id),
    KEY idx_users_program (program_id),
    CONSTRAINT fk_users_role    FOREIGN KEY (role_id)    REFERENCES roles(role_id),
    CONSTRAINT fk_users_program FOREIGN KEY (program_id) REFERENCES programs(program_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE document_types (
    doc_type_id INT AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(80)  NOT NULL,
    description VARCHAR(255) DEFAULT NULL,
    is_active   TINYINT(1)   NOT NULL DEFAULT 1,
    created_by  INT          DEFAULT NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_doctype_name (name),
    KEY idx_doctype_createdby (created_by),
    CONSTRAINT fk_doctype_user FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE academic_periods (
    period_id   INT AUTO_INCREMENT PRIMARY KEY,
    school_year VARCHAR(9)  NOT NULL,
    semester    ENUM('1st','2nd','Summer') NOT NULL,
    label       VARCHAR(60) DEFAULT NULL,
    start_date  DATE        DEFAULT NULL,
    end_date    DATE        DEFAULT NULL,
    is_active   TINYINT(1)  NOT NULL DEFAULT 0,
    UNIQUE KEY uk_period (school_year, semester)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE requirements (
    requirement_id INT AUTO_INCREMENT PRIMARY KEY,
    doc_type_id    INT          NOT NULL,
    period_id      INT          NOT NULL,
    title          VARCHAR(120) NOT NULL,
    description    VARCHAR(255) DEFAULT NULL,
    -- Audience (FR-35). The payload column depends on applies_to:
    --   'all_faculty' -> neither column is read
    --   'program'     -> target_program_id names the program
    --   'individual'  -> the requirement_targets rows list the faculty
    applies_to     ENUM('all_faculty','program','individual') NOT NULL DEFAULT 'all_faculty',
    target_program_id INT       DEFAULT NULL,
    deadline       DATE         DEFAULT NULL,
    created_by     INT          DEFAULT NULL,
    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_req_doctype (doc_type_id),
    KEY idx_req_period (period_id),
    KEY idx_req_deadline (deadline),
    KEY idx_req_createdby (created_by),
    KEY idx_req_target_program (target_program_id),
    CONSTRAINT fk_req_doctype  FOREIGN KEY (doc_type_id)       REFERENCES document_types(doc_type_id),
    CONSTRAINT fk_req_period   FOREIGN KEY (period_id)         REFERENCES academic_periods(period_id),
    CONSTRAINT fk_req_user     FOREIGN KEY (created_by)        REFERENCES users(user_id) ON DELETE SET NULL,
    CONSTRAINT fk_req_program  FOREIGN KEY (target_program_id) REFERENCES programs(program_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The individually-picked audience of a requirement (FR-35), read only when
-- requirements.applies_to = 'individual'. Kept as its own table rather than a
-- delimited column so the audience can be resolved in SQL (see
-- Submission::backfillForFaculty()) and so the FK keeps the ids honest.
CREATE TABLE requirement_targets (
    requirement_id INT NOT NULL,
    faculty_id     INT NOT NULL,
    PRIMARY KEY (requirement_id, faculty_id),
    KEY idx_reqtarget_faculty (faculty_id),
    CONSTRAINT fk_reqtarget_req     FOREIGN KEY (requirement_id) REFERENCES requirements(requirement_id) ON DELETE CASCADE,
    CONSTRAINT fk_reqtarget_faculty FOREIGN KEY (faculty_id)     REFERENCES users(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE submissions (
    submission_id   INT AUTO_INCREMENT PRIMARY KEY,
    requirement_id  INT NOT NULL,
    faculty_id      INT NOT NULL,
    status          ENUM('Pending','Submitted','Approved','Revised') NOT NULL DEFAULT 'Pending',
    current_version INT NOT NULL DEFAULT 0,
    submitted_at    DATETIME DEFAULT NULL,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_sub_req_fac (requirement_id, faculty_id),
    KEY idx_sub_faculty (faculty_id),
    KEY idx_sub_status (status),
    CONSTRAINT fk_sub_req     FOREIGN KEY (requirement_id) REFERENCES requirements(requirement_id) ON DELETE CASCADE,
    CONSTRAINT fk_sub_faculty FOREIGN KEY (faculty_id)     REFERENCES users(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE document_files (
    file_id       INT AUTO_INCREMENT PRIMARY KEY,
    submission_id INT          NOT NULL,
    uploaded_by   INT          NOT NULL,
    file_name     VARCHAR(255) NOT NULL,
    file_path     VARCHAR(255) NOT NULL,
    mime_type     VARCHAR(100) NOT NULL,
    file_size     INT          NOT NULL,
    version_no    INT          NOT NULL,
    uploaded_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_file_sub_version (submission_id, version_no),
    KEY idx_file_sub (submission_id),
    KEY idx_file_uploader (uploaded_by),
    CONSTRAINT fk_file_sub  FOREIGN KEY (submission_id) REFERENCES submissions(submission_id) ON DELETE CASCADE,
    CONSTRAINT fk_file_user FOREIGN KEY (uploaded_by)   REFERENCES users(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE reviews (
    review_id     INT AUTO_INCREMENT PRIMARY KEY,
    submission_id INT  NOT NULL,
    reviewer_id   INT  NOT NULL,
    decision      ENUM('Approved','Revised') NOT NULL,
    comments      TEXT DEFAULT NULL,
    reviewed_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_rev_sub (submission_id),
    KEY idx_rev_reviewer (reviewer_id),
    CONSTRAINT fk_rev_sub  FOREIGN KEY (submission_id) REFERENCES submissions(submission_id) ON DELETE CASCADE,
    CONSTRAINT fk_rev_user FOREIGN KEY (reviewer_id)   REFERENCES users(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE notifications (
    notification_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id         INT          NOT NULL,
    submission_id   INT          DEFAULT NULL,
    type            ENUM('deadline','pending','status_change') NOT NULL,
    title           VARCHAR(120) NOT NULL,
    message         VARCHAR(255) NOT NULL,
    is_read         TINYINT(1)   NOT NULL DEFAULT 0,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_notif_user_read (user_id, is_read),
    KEY idx_notif_sub (submission_id),
    -- Serves the reminder dedup LEFT JOIN (same user+type+submission on a given
    -- day) so it stays an index seek, not a per-load table scan, as the table grows.
    KEY idx_notif_dedup (user_id, type, submission_id, created_at),
    -- Serves the notification centre's "newest first for this user" ordering.
    KEY idx_notif_user_created (user_id, created_at),
    CONSTRAINT fk_notif_user FOREIGN KEY (user_id)       REFERENCES users(user_id) ON DELETE CASCADE,
    CONSTRAINT fk_notif_sub  FOREIGN KEY (submission_id) REFERENCES submissions(submission_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE audit_log (
    log_id      INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT          NOT NULL,
    action      VARCHAR(40)  NOT NULL,
    entity_type VARCHAR(40)  NOT NULL,
    entity_id   INT          DEFAULT NULL,
    details     VARCHAR(255) DEFAULT NULL,
    ip_address  VARCHAR(45)  DEFAULT NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_audit_user (user_id),
    KEY idx_audit_action (action),
    KEY idx_audit_created (created_at),
    CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Brute-force throttle log (Phase 3 remediation, Bucket B). Deliberately NOT a
-- foreign-key child of users: a failed attempt may name an email that does not
-- exist, and the login endpoint must not create/validate rows against users
-- just to record an attempt.
CREATE TABLE login_attempts (
    attempt_id   INT AUTO_INCREMENT PRIMARY KEY,
    email        VARCHAR(120) NOT NULL,
    ip_address   VARCHAR(45)  NOT NULL,
    success      TINYINT(1)   NOT NULL DEFAULT 0,
    attempted_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_login_attempts_lookup (email, ip_address, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
