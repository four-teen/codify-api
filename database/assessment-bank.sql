SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS assessment_banks (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    faculty_id BIGINT UNSIGNED NOT NULL,
    code VARCHAR(50) NOT NULL,
    title VARCHAR(200) NOT NULL,
    bank_type ENUM('quiz', 'exam') NOT NULL DEFAULT 'quiz',
    description TEXT NULL,
    instructions MEDIUMTEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_assessment_banks_faculty_code (faculty_id, code),
    KEY idx_assessment_banks_type (bank_type),
    KEY idx_assessment_banks_active (is_active),
    CONSTRAINT fk_assessment_banks_faculty FOREIGN KEY (faculty_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS assessment_bank_subjects (
    assessment_bank_id BIGINT UNSIGNED NOT NULL,
    faculty_subject_id BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (assessment_bank_id, faculty_subject_id),
    KEY idx_assessment_bank_subjects_offering (faculty_subject_id),
    CONSTRAINT fk_assessment_bank_subjects_bank FOREIGN KEY (assessment_bank_id) REFERENCES assessment_banks(id) ON DELETE CASCADE,
    CONSTRAINT fk_assessment_bank_subjects_offering FOREIGN KEY (faculty_subject_id) REFERENCES faculty_subjects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS assessment_bank_questions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    assessment_bank_id BIGINT UNSIGNED NOT NULL,
    position SMALLINT UNSIGNED NOT NULL,
    question_type ENUM('multiple_choice', 'checkboxes', 'dropdown', 'true_false', 'short_answer', 'paragraph') NOT NULL,
    question_text MEDIUMTEXT NOT NULL,
    options_json MEDIUMTEXT NULL,
    correct_answers_json TEXT NULL,
    accepted_answers_json TEXT NULL,
    case_sensitive TINYINT(1) NOT NULL DEFAULT 0,
    points SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    is_required TINYINT(1) NOT NULL DEFAULT 1,
    answer_explanation TEXT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_assessment_bank_question_position (assessment_bank_id, position),
    KEY idx_assessment_bank_questions_type (question_type),
    CONSTRAINT fk_assessment_bank_questions_bank FOREIGN KEY (assessment_bank_id) REFERENCES assessment_banks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
