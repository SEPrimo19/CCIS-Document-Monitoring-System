-- Reference / seed data for CCIS-DMS.
-- Run after schema.sql (which recreates the tables). The default admin USER is
-- created by scripts/migrate.php so its password can be bcrypt-hashed in PHP.

-- Two roles, matching the two real end-users of the CCIS office. The College
-- Secretary both administers the system and verifies submitted documents; the
-- academic sign-off happens offline (faculty upload already-signed documents),
-- so the in-system approval is a clerical verify-and-record step.
-- NOTE: no semicolons inside these string literals — scripts/migrate.php splits
-- this file on ';' to run one statement at a time, so an embedded semicolon
-- would truncate the INSERT mid-string.
INSERT INTO roles (role_name, description) VALUES
    ('Secretary', 'College Secretary. Manages users, document types, requirements and periods, monitors compliance, verifies (approves or returns) submitted documents, and generates reports.'),
    ('Faculty',   'Uploads required documents and tracks their submission status.');

-- The college's academic programs (FR-36). Reference data the Secretary
-- maintains; a faculty account is assigned one of these, and a requirement can
-- be targeted at one (FR-35). The Secretary's own account has no program.
INSERT INTO programs (code, name, is_active) VALUES
    ('BSIT', 'Bachelor of Science in Information Technology', 1),
    ('BSIS', 'Bachelor of Science in Information Systems',    1),
    ('BSCS', 'Bachelor of Science in Computer Science',       1),
    ('EMC',  'Entertainment and Multimedia Computing',        1);

INSERT INTO document_types (name, description, is_active) VALUES
    ('Teaching Load',                 'Faculty teaching load per semester.',            1),
    ('Syllabus',                      'Course syllabus.',                               1),
    ('Table of Specifications (TOS)', 'Examination table of specifications.',           1),
    ('DPCR/OPCR Comments & Reviews',  'Performance commitment and review documents.',   1),
    ('Year-end Portfolio',            'Faculty year-end portfolio.',                    1);

INSERT INTO academic_periods (school_year, semester, label, is_active) VALUES
    ('2026-2027', '1st', 'AY 2026-2027, 1st Semester', 1);
