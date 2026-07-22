-- ============================================================================
-- CCIS-DMS database schema
-- Northwest Samar State University — CCIS Document Monitoring System
-- Generated from the finalized ERD & Data Dictionary in Design/05-data-model.md
--
-- Locked design inputs (2026-07-20):
--   Roles:          Administrator, Reviewer/Approver (Program Chair), Faculty
--   Workflow:       upload -> review -> approve / return-for-revision -> recorded
--   Notifications:  in-app only
--   Archiving:      by academic year (via academic_periods.is_active)
--   Audit log:      submit/resubmit/approve/return + admin config changes (admin view)
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
DROP TABLE IF EXISTS requirements;
DROP TABLE IF EXISTS academic_periods;
DROP TABLE IF EXISTS document_types;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS roles;
SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE roles (
    role_id     INT AUTO_INCREMENT PRIMARY KEY,
    role_name   VARCHAR(30)  NOT NULL,
    description VARCHAR(255) DEFAULT NULL,
    UNIQUE KEY uk_roles_name (role_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE users (
    user_id       INT AUTO_INCREMENT PRIMARY KEY,
    role_id       INT          NOT NULL,
    employee_no   VARCHAR(30)  DEFAULT NULL,
    first_name    VARCHAR(60)  NOT NULL,
    last_name     VARCHAR(60)  NOT NULL,
    email         VARCHAR(120) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    program_dept  VARCHAR(80)  DEFAULT NULL,
    status        ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_login    DATETIME     DEFAULT NULL,
    UNIQUE KEY uk_users_email (email),
    UNIQUE KEY uk_users_empno (employee_no),
    KEY idx_users_role (role_id),
    CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles(role_id)
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
    applies_to     ENUM('all_faculty','program','individual') NOT NULL DEFAULT 'all_faculty',
    deadline       DATE         DEFAULT NULL,
    created_by     INT          DEFAULT NULL,
    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_req_doctype (doc_type_id),
    KEY idx_req_period (period_id),
    KEY idx_req_deadline (deadline),
    KEY idx_req_createdby (created_by),
    CONSTRAINT fk_req_doctype FOREIGN KEY (doc_type_id) REFERENCES document_types(doc_type_id),
    CONSTRAINT fk_req_period  FOREIGN KEY (period_id)   REFERENCES academic_periods(period_id),
    CONSTRAINT fk_req_user    FOREIGN KEY (created_by)  REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE submissions (
    submission_id   INT AUTO_INCREMENT PRIMARY KEY,
    requirement_id  INT NOT NULL,
    faculty_id      INT NOT NULL,
    status          ENUM('Pending','Submitted','Approved','Returned-for-revision') NOT NULL DEFAULT 'Pending',
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
    decision      ENUM('Approved','Returned-for-revision') NOT NULL,
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
