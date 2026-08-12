CREATE DATABASE IF NOT EXISTS codify_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE codify_db;

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    faculty_id BIGINT UNSIGNED NULL,
    first_name VARCHAR(100) NULL,
    last_name VARCHAR(100) NULL,
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

CREATE TABLE IF NOT EXISTS code_execution_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    student_id BIGINT UNSIGNED NOT NULL,
    attempt_key CHAR(64) NOT NULL,
    attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_code_execution_attempts_key_time (attempt_key, attempted_at),
    KEY idx_code_execution_attempts_student (student_id),
    CONSTRAINT fk_code_execution_attempts_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS campuses (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, code VARCHAR(50) NOT NULL, name VARCHAR(200) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1, created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, PRIMARY KEY (id),
    UNIQUE KEY uq_campuses_code (code), UNIQUE KEY uq_campuses_name (name), KEY idx_campuses_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS colleges (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, campus_id BIGINT UNSIGNED NOT NULL, code VARCHAR(50) NOT NULL,
    name VARCHAR(200) NOT NULL, is_active TINYINT(1) NOT NULL DEFAULT 1, created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, PRIMARY KEY (id),
    UNIQUE KEY uq_colleges_campus_code (campus_id, code), UNIQUE KEY uq_colleges_campus_name (campus_id, name),
    KEY idx_colleges_active (is_active), CONSTRAINT fk_colleges_campus FOREIGN KEY (campus_id) REFERENCES campuses(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS programs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, college_id BIGINT UNSIGNED NOT NULL, code VARCHAR(50) NOT NULL,
    name VARCHAR(200) NOT NULL, is_active TINYINT(1) NOT NULL DEFAULT 1, created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, PRIMARY KEY (id),
    UNIQUE KEY uq_programs_college_code (college_id, code), UNIQUE KEY uq_programs_college_name (college_id, name),
    KEY idx_programs_active (is_active), CONSTRAINT fk_programs_college FOREIGN KEY (college_id) REFERENCES colleges(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS subjects (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, program_id BIGINT UNSIGNED NOT NULL, code VARCHAR(50) NOT NULL,
    name VARCHAR(200) NOT NULL, units TINYINT UNSIGNED NOT NULL DEFAULT 3, is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id), UNIQUE KEY uq_subjects_program_code (program_id, code), UNIQUE KEY uq_subjects_program_name (program_id, name),
    KEY idx_subjects_active (is_active), CONSTRAINT fk_subjects_program FOREIGN KEY (program_id) REFERENCES programs(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS faculty_profiles (
    user_id BIGINT UNSIGNED NOT NULL, campus_id BIGINT UNSIGNED NOT NULL, created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id), KEY idx_faculty_profiles_campus (campus_id), CONSTRAINT fk_faculty_profiles_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_faculty_profiles_campus FOREIGN KEY (campus_id) REFERENCES campuses(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS faculty_colleges (
    faculty_id BIGINT UNSIGNED NOT NULL, college_id BIGINT UNSIGNED NOT NULL, created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (faculty_id, college_id), KEY idx_faculty_colleges_college (college_id), CONSTRAINT fk_faculty_colleges_faculty FOREIGN KEY (faculty_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_faculty_colleges_college FOREIGN KEY (college_id) REFERENCES colleges(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS faculty_programs (
    faculty_id BIGINT UNSIGNED NOT NULL, program_id BIGINT UNSIGNED NOT NULL, created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (faculty_id, program_id), KEY idx_faculty_programs_program (program_id), CONSTRAINT fk_faculty_programs_faculty FOREIGN KEY (faculty_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_faculty_programs_program FOREIGN KEY (program_id) REFERENCES programs(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS student_profiles (
    user_id BIGINT UNSIGNED NOT NULL, program_id BIGINT UNSIGNED NOT NULL, student_number VARCHAR(50) NULL,
    gender VARCHAR(30) NULL, mobile_number VARCHAR(30) NULL, course_label VARCHAR(255) NULL, enrollment_status VARCHAR(100) NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id), UNIQUE KEY uq_student_profiles_number (student_number), KEY idx_student_profiles_program (program_id),
    CONSTRAINT fk_student_profiles_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_student_profiles_program FOREIGN KEY (program_id) REFERENCES programs(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS faculty_subjects (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, faculty_id BIGINT UNSIGNED NOT NULL, subject_id BIGINT UNSIGNED NOT NULL,
    section VARCHAR(100) NOT NULL DEFAULT '', class_schedule VARCHAR(255) NULL, academic_year VARCHAR(20) NOT NULL, academic_term VARCHAR(100) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1, created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id), UNIQUE KEY uq_faculty_subject_term (faculty_id, subject_id, section, academic_year, academic_term),
    KEY idx_faculty_subjects_subject (subject_id), KEY idx_faculty_subjects_term (academic_year, academic_term),
    CONSTRAINT fk_faculty_subjects_faculty FOREIGN KEY (faculty_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_faculty_subjects_subject FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS faculty_subject_syllabi (
    faculty_subject_id BIGINT UNSIGNED NOT NULL, original_name VARCHAR(255) NOT NULL, stored_name VARCHAR(100) NOT NULL,
    mime_type VARCHAR(100) NOT NULL DEFAULT 'application/pdf', size_bytes BIGINT UNSIGNED NOT NULL,
    uploaded_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (faculty_subject_id), UNIQUE KEY uq_faculty_subject_syllabi_stored_name (stored_name),
    CONSTRAINT fk_faculty_subject_syllabi_subject FOREIGN KEY (faculty_subject_id) REFERENCES faculty_subjects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS faculty_subject_students (
    faculty_subject_id BIGINT UNSIGNED NOT NULL, student_id BIGINT UNSIGNED NOT NULL, source ENUM('manual', 'import') NOT NULL DEFAULT 'manual', created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (faculty_subject_id, student_id), KEY idx_faculty_subject_students_student (student_id),
    CONSTRAINT fk_faculty_subject_students_subject FOREIGN KEY (faculty_subject_id) REFERENCES faculty_subjects(id) ON DELETE CASCADE,
    CONSTRAINT fk_faculty_subject_students_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS coding_problems (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, faculty_id BIGINT UNSIGNED NOT NULL, code VARCHAR(50) NOT NULL, title VARCHAR(200) NOT NULL,
    language ENUM('python') NOT NULL DEFAULT 'python', difficulty ENUM('beginner', 'intermediate', 'advanced') NOT NULL DEFAULT 'beginner',
    problem_statement MEDIUMTEXT NOT NULL, input_format TEXT NULL, output_format TEXT NULL, constraints_text TEXT NULL,
    starter_code MEDIUMTEXT NULL, reference_solution MEDIUMTEXT NULL, solution_notes MEDIUMTEXT NULL, tags VARCHAR(500) NULL,
    time_limit_ms SMALLINT UNSIGNED NOT NULL DEFAULT 2000, memory_limit_mb SMALLINT UNSIGNED NOT NULL DEFAULT 128, is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id), UNIQUE KEY uq_coding_problems_faculty_code (faculty_id, code), KEY idx_coding_problems_difficulty (difficulty), KEY idx_coding_problems_active (is_active),
    CONSTRAINT fk_coding_problems_faculty FOREIGN KEY (faculty_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS coding_problem_subjects (
    problem_id BIGINT UNSIGNED NOT NULL, faculty_subject_id BIGINT UNSIGNED NOT NULL, created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (problem_id, faculty_subject_id), KEY idx_coding_problem_subjects_offering (faculty_subject_id),
    CONSTRAINT fk_problem_subjects_problem FOREIGN KEY (problem_id) REFERENCES coding_problems(id) ON DELETE CASCADE,
    CONSTRAINT fk_problem_subjects_offering FOREIGN KEY (faculty_subject_id) REFERENCES faculty_subjects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS coding_problem_test_cases (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, problem_id BIGINT UNSIGNED NOT NULL, position SMALLINT UNSIGNED NOT NULL,
    input_data MEDIUMTEXT NOT NULL, expected_output MEDIUMTEXT NOT NULL, is_sample TINYINT(1) NOT NULL DEFAULT 0, points SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id), UNIQUE KEY uq_problem_test_case_position (problem_id, position), KEY idx_problem_test_cases_sample (problem_id, is_sample),
    CONSTRAINT fk_problem_test_cases_problem FOREIGN KEY (problem_id) REFERENCES coding_problems(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS assessment_banks (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, faculty_id BIGINT UNSIGNED NOT NULL, code VARCHAR(50) NOT NULL,
    title VARCHAR(200) NOT NULL, bank_type ENUM('quiz', 'exam') NOT NULL DEFAULT 'quiz', description TEXT NULL,
    instructions MEDIUMTEXT NULL, is_active TINYINT(1) NOT NULL DEFAULT 0, created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, PRIMARY KEY (id),
    UNIQUE KEY uq_assessment_banks_faculty_code (faculty_id, code), KEY idx_assessment_banks_type (bank_type), KEY idx_assessment_banks_active (is_active),
    CONSTRAINT fk_assessment_banks_faculty FOREIGN KEY (faculty_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS assessment_bank_subjects (
    assessment_bank_id BIGINT UNSIGNED NOT NULL, faculty_subject_id BIGINT UNSIGNED NOT NULL, created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (assessment_bank_id, faculty_subject_id), KEY idx_assessment_bank_subjects_offering (faculty_subject_id),
    CONSTRAINT fk_assessment_bank_subjects_bank FOREIGN KEY (assessment_bank_id) REFERENCES assessment_banks(id) ON DELETE CASCADE,
    CONSTRAINT fk_assessment_bank_subjects_offering FOREIGN KEY (faculty_subject_id) REFERENCES faculty_subjects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS assessment_bank_questions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, assessment_bank_id BIGINT UNSIGNED NOT NULL, position SMALLINT UNSIGNED NOT NULL,
    question_type ENUM('multiple_choice', 'checkboxes', 'dropdown', 'true_false', 'short_answer', 'paragraph') NOT NULL,
    question_text MEDIUMTEXT NOT NULL, options_json MEDIUMTEXT NULL, correct_answers_json TEXT NULL, accepted_answers_json TEXT NULL,
    case_sensitive TINYINT(1) NOT NULL DEFAULT 0, points SMALLINT UNSIGNED NOT NULL DEFAULT 1, is_required TINYINT(1) NOT NULL DEFAULT 1,
    answer_explanation TEXT NULL, created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id), UNIQUE KEY uq_assessment_bank_question_position (assessment_bank_id, position), KEY idx_assessment_bank_questions_type (question_type),
    CONSTRAINT fk_assessment_bank_questions_bank FOREIGN KEY (assessment_bank_id) REFERENCES assessment_banks(id) ON DELETE CASCADE
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
