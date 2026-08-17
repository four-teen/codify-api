SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS coding_problems (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    faculty_id BIGINT UNSIGNED NOT NULL,
    code VARCHAR(50) NOT NULL,
    title VARCHAR(200) NOT NULL,
    language ENUM('python') NOT NULL DEFAULT 'python',
    difficulty ENUM('beginner', 'intermediate', 'advanced') NOT NULL DEFAULT 'beginner',
    problem_statement MEDIUMTEXT NOT NULL,
    input_format TEXT NULL,
    output_format TEXT NULL,
    constraints_text TEXT NULL,
    starter_code MEDIUMTEXT NULL,
    reference_solution MEDIUMTEXT NULL,
    solution_notes MEDIUMTEXT NULL,
    tags VARCHAR(500) NULL,
    time_limit_ms SMALLINT UNSIGNED NOT NULL DEFAULT 2000,
    memory_limit_mb SMALLINT UNSIGNED NOT NULL DEFAULT 128,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_coding_problems_faculty_code (faculty_id, code),
    KEY idx_coding_problems_difficulty (difficulty),
    KEY idx_coding_problems_active (is_active),
    CONSTRAINT fk_coding_problems_faculty FOREIGN KEY (faculty_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS coding_problem_subjects (
    problem_id BIGINT UNSIGNED NOT NULL,
    faculty_subject_id BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (problem_id, faculty_subject_id),
    KEY idx_coding_problem_subjects_offering (faculty_subject_id),
    CONSTRAINT fk_problem_subjects_problem FOREIGN KEY (problem_id) REFERENCES coding_problems(id) ON DELETE CASCADE,
    CONSTRAINT fk_problem_subjects_offering FOREIGN KEY (faculty_subject_id) REFERENCES faculty_subjects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS coding_problem_test_cases (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    problem_id BIGINT UNSIGNED NOT NULL,
    position SMALLINT UNSIGNED NOT NULL,
    input_data MEDIUMTEXT NOT NULL,
    expected_output MEDIUMTEXT NOT NULL,
    is_sample TINYINT(1) NOT NULL DEFAULT 0,
    points SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_problem_test_case_position (problem_id, position),
    KEY idx_problem_test_cases_sample (problem_id, is_sample),
    CONSTRAINT fk_problem_test_cases_problem FOREIGN KEY (problem_id) REFERENCES coding_problems(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
