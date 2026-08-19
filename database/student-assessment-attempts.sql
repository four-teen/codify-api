CREATE TABLE IF NOT EXISTS student_assessment_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    assessment_bank_id BIGINT UNSIGNED NOT NULL,
    faculty_subject_id BIGINT UNSIGNED NOT NULL,
    student_id BIGINT UNSIGNED NOT NULL,
    answers_json MEDIUMTEXT NOT NULL,
    grading_status ENUM('graded', 'pending_review') NOT NULL DEFAULT 'graded',
    auto_score INT UNSIGNED NOT NULL DEFAULT 0,
    total_points INT UNSIGNED NOT NULL DEFAULT 0,
    pending_review_points INT UNSIGNED NOT NULL DEFAULT 0,
    submitted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_student_assessment_attempts_student (student_id, submitted_at),
    KEY idx_student_assessment_attempts_bank (assessment_bank_id, student_id),
    KEY idx_student_assessment_attempts_subject (faculty_subject_id, student_id),
    CONSTRAINT fk_student_assessment_attempts_bank FOREIGN KEY (assessment_bank_id) REFERENCES assessment_banks(id) ON DELETE CASCADE,
    CONSTRAINT fk_student_assessment_attempts_subject FOREIGN KEY (faculty_subject_id) REFERENCES faculty_subjects(id) ON DELETE CASCADE,
    CONSTRAINT fk_student_assessment_attempts_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS student_assessment_retake_permissions (
    assessment_bank_id BIGINT UNSIGNED NOT NULL,
    faculty_subject_id BIGINT UNSIGNED NOT NULL,
    student_id BIGINT UNSIGNED NOT NULL,
    additional_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    granted_by BIGINT UNSIGNED NOT NULL,
    granted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (assessment_bank_id, faculty_subject_id, student_id),
    KEY idx_student_assessment_retakes_student (student_id, faculty_subject_id),
    KEY idx_student_assessment_retakes_faculty (granted_by),
    CONSTRAINT fk_student_assessment_retakes_bank FOREIGN KEY (assessment_bank_id) REFERENCES assessment_banks(id) ON DELETE CASCADE,
    CONSTRAINT fk_student_assessment_retakes_subject FOREIGN KEY (faculty_subject_id) REFERENCES faculty_subjects(id) ON DELETE CASCADE,
    CONSTRAINT fk_student_assessment_retakes_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_student_assessment_retakes_faculty FOREIGN KEY (granted_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
