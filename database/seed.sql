-- Reference / seed data for CCIS-DMS.
-- Run after schema.sql (which recreates the tables). The default admin USER is
-- created by scripts/migrate.php so its password can be bcrypt-hashed in PHP.

INSERT INTO roles (role_name, description) VALUES
    ('Administrator',     'Full system management: users, document types, requirements, reports, and audit log.'),
    ('Reviewer/Approver', 'Reviews submitted documents and approves or returns them for revision.'),
    ('Faculty',           'Uploads required documents and tracks their submission status.');

INSERT INTO document_types (name, description, is_active) VALUES
    ('Teaching Load',                 'Faculty teaching load per semester.',            1),
    ('Syllabus',                      'Course syllabus.',                               1),
    ('Table of Specifications (TOS)', 'Examination table of specifications.',           1),
    ('DPCR/OPCR Comments & Reviews',  'Performance commitment and review documents.',   1),
    ('Year-end Portfolio',            'Faculty year-end portfolio.',                    1);

INSERT INTO academic_periods (school_year, semester, label, is_active) VALUES
    ('2026-2027', '1st', 'AY 2026-2027, 1st Semester', 1);
