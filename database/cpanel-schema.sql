SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    faculty_id BIGINT UNSIGNED NULL,
    name VARCHAR(255) NOT NULL,
    username VARCHAR(100) NULL,
    email VARCHAR(255) NOT NULL,
    email_verified_at TIMESTAMP NULL,
    password VARCHAR(255) NOT NULL,
    role ENUM('administrator', 'student', 'faculty') NOT NULL DEFAULT 'student',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    must_change_password TINYINT(1) NOT NULL DEFAULT 0,
    remember_token VARCHAR(100) NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_username (username),
    UNIQUE KEY uq_users_email (email),
    KEY idx_users_role (role),
    KEY idx_users_active (is_active),
    KEY idx_users_faculty (faculty_id),
    CONSTRAINT fk_users_faculty FOREIGN KEY (faculty_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS permissions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code VARCHAR(120) NOT NULL,
    label VARCHAR(160) NOT NULL,
    `group` VARCHAR(80) NOT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_permissions_code (code),
    KEY idx_permissions_group (`group`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_permissions (
    user_id BIGINT UNSIGNED NOT NULL,
    permission_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (user_id, permission_id),
    CONSTRAINT fk_user_permissions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_user_permissions_permission FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS personal_access_tokens (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tokenable_type VARCHAR(255) NOT NULL,
    tokenable_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(255) NOT NULL,
    token VARCHAR(64) NOT NULL,
    abilities TEXT NULL,
    last_used_at TIMESTAMP NULL,
    expires_at TIMESTAMP NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_access_tokens_token (token),
    KEY idx_access_tokens_owner (tokenable_type, tokenable_id),
    KEY idx_access_tokens_expiry (expires_at),
    CONSTRAINT fk_access_tokens_user FOREIGN KEY (tokenable_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS system_settings (
    id BIGINT UNSIGNED NOT NULL,
    institution_name VARCHAR(160) NOT NULL DEFAULT 'Codify',
    institution_logo_url VARCHAR(2048) NULL,
    timezone VARCHAR(80) NOT NULL DEFAULT 'Asia/Manila',
    academic_year VARCHAR(20) NOT NULL,
    academic_term VARCHAR(100) NOT NULL DEFAULT 'First Semester',
    faculty_student_management_enabled TINYINT(1) NOT NULL DEFAULT 1,
    temporary_password_change_required TINYINT(1) NOT NULL DEFAULT 1,
    session_timeout_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 120,
    max_failed_login_attempts TINYINT UNSIGNED NOT NULL DEFAULT 5,
    maintenance_mode TINYINT(1) NOT NULL DEFAULT 0,
    announcement_enabled TINYINT(1) NOT NULL DEFAULT 0,
    announcement_message TEXT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    attempt_key CHAR(64) NOT NULL,
    attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_login_attempts_key_time (attempt_key, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS password_resets (
    email VARCHAR(255) NOT NULL,
    token VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NULL,
    KEY idx_password_resets_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permissions (code, label, `group`) VALUES
('dashboard.view', 'View dashboard', 'General'),
('courses.view', 'View courses', 'Courses'),
('courses.manage', 'Manage courses', 'Courses'),
('exercises.attempt', 'Attempt coding exercises', 'Coding'),
('problems.manage', 'Manage problem bank', 'Coding'),
('assignments.submit', 'Submit assignments', 'Assignments'),
('assignments.manage', 'Manage assignments', 'Assignments'),
('exams.take', 'Take quizzes and examinations', 'Assessment'),
('exams.manage', 'Manage quizzes and examinations', 'Assessment'),
('progress.view', 'View own progress', 'Analytics'),
('analytics.view', 'View student analytics', 'Analytics'),
('integrity.monitor', 'Monitor fingerprint risk events', 'Academic Integrity'),
('integrity.reports', 'View academic integrity reports', 'Academic Integrity'),
('research.export', 'Export approved research datasets', 'Research'),
('users.manage', 'Manage users', 'Administration'),
('settings.manage', 'Manage system settings', 'Administration'),
('compiler.configure', 'Configure compiler services', 'Administration'),
('fingerprint.configure', 'Configure fingerprint collection', 'Administration'),
('risk.configure', 'Configure AI risk scoring', 'Administration'),
('audit.view', 'View audit logs', 'Administration')
ON DUPLICATE KEY UPDATE label = VALUES(label), `group` = VALUES(`group`);

INSERT INTO system_settings (
    id, institution_name, institution_logo_url, timezone, academic_year, academic_term,
    faculty_student_management_enabled, temporary_password_change_required,
    session_timeout_minutes, max_failed_login_attempts, maintenance_mode,
    announcement_enabled, announcement_message
) VALUES (
    1, 'Codify', '/codify-logo-official.png', 'Asia/Manila',
    CONCAT(YEAR(CURRENT_DATE), '-', YEAR(CURRENT_DATE) + 1), 'First Semester',
    1, 1, 120, 5, 0, 0, NULL
) ON DUPLICATE KEY UPDATE id = VALUES(id);

SET FOREIGN_KEY_CHECKS = 1;
