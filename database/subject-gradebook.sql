SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS subject_grading_settings (
    faculty_subject_id BIGINT UNSIGNED NOT NULL,
    base_grade DECIMAL(5,2) NOT NULL DEFAULT 40.00,
    transmutation_span DECIMAL(5,2) NOT NULL DEFAULT 60.00,
    midterm_status ENUM('draft', 'published', 'locked') NOT NULL DEFAULT 'draft',
    final_status ENUM('draft', 'published', 'locked') NOT NULL DEFAULT 'draft',
    updated_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (faculty_subject_id),
    CONSTRAINT fk_subject_grading_settings_subject FOREIGN KEY (faculty_subject_id) REFERENCES faculty_subjects(id) ON DELETE CASCADE,
    CONSTRAINT fk_subject_grading_settings_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS subject_grade_categories (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    faculty_subject_id BIGINT UNSIGNED NOT NULL,
    grading_period ENUM('midterm', 'final') NOT NULL,
    category_key VARCHAR(50) NOT NULL,
    name VARCHAR(150) NOT NULL,
    weight DECIMAL(5,2) NOT NULL,
    position SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_subject_grade_category (faculty_subject_id, grading_period, category_key),
    KEY idx_subject_grade_categories_period (faculty_subject_id, grading_period, position),
    CONSTRAINT fk_subject_grade_categories_subject FOREIGN KEY (faculty_subject_id) REFERENCES faculty_subjects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS subject_grade_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    faculty_subject_id BIGINT UNSIGNED NOT NULL,
    category_id BIGINT UNSIGNED NOT NULL,
    source_type ENUM('assessment', 'problem', 'manual') NOT NULL DEFAULT 'manual',
    assessment_bank_id BIGINT UNSIGNED NULL,
    coding_problem_id BIGINT UNSIGNED NULL,
    title VARCHAR(200) NOT NULL,
    max_points DECIMAL(10,2) NOT NULL DEFAULT 100.00,
    counts_toward_grade TINYINT(1) NOT NULL DEFAULT 1,
    due_at DATETIME NULL,
    position SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_subject_grade_item_assessment (faculty_subject_id, assessment_bank_id),
    UNIQUE KEY uq_subject_grade_item_problem (faculty_subject_id, coding_problem_id),
    KEY idx_subject_grade_items_category (category_id, position),
    CONSTRAINT fk_subject_grade_items_subject FOREIGN KEY (faculty_subject_id) REFERENCES faculty_subjects(id) ON DELETE CASCADE,
    CONSTRAINT fk_subject_grade_items_category FOREIGN KEY (category_id) REFERENCES subject_grade_categories(id) ON DELETE CASCADE,
    CONSTRAINT fk_subject_grade_items_assessment FOREIGN KEY (assessment_bank_id) REFERENCES assessment_banks(id) ON DELETE CASCADE,
    CONSTRAINT fk_subject_grade_items_problem FOREIGN KEY (coding_problem_id) REFERENCES coding_problems(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS student_grade_entries (
    grade_item_id BIGINT UNSIGNED NOT NULL,
    student_id BIGINT UNSIGNED NOT NULL,
    score DECIMAL(10,2) NULL,
    status ENUM('graded', 'missing', 'excused', 'pending') NOT NULL DEFAULT 'graded',
    remarks VARCHAR(1000) NULL,
    graded_by BIGINT UNSIGNED NULL,
    graded_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (grade_item_id, student_id),
    KEY idx_student_grade_entries_student (student_id, updated_at),
    CONSTRAINT fk_student_grade_entries_item FOREIGN KEY (grade_item_id) REFERENCES subject_grade_items(id) ON DELETE CASCADE,
    CONSTRAINT fk_student_grade_entries_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_student_grade_entries_faculty FOREIGN KEY (graded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS subject_grade_audit_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    faculty_subject_id BIGINT UNSIGNED NOT NULL,
    grade_item_id BIGINT UNSIGNED NULL,
    student_id BIGINT UNSIGNED NULL,
    faculty_id BIGINT UNSIGNED NULL,
    action VARCHAR(80) NOT NULL,
    previous_json MEDIUMTEXT NULL,
    current_json MEDIUMTEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_subject_grade_audit_subject (faculty_subject_id, created_at),
    CONSTRAINT fk_subject_grade_audit_subject FOREIGN KEY (faculty_subject_id) REFERENCES faculty_subjects(id) ON DELETE CASCADE,
    CONSTRAINT fk_subject_grade_audit_item FOREIGN KEY (grade_item_id) REFERENCES subject_grade_items(id) ON DELETE SET NULL,
    CONSTRAINT fk_subject_grade_audit_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_subject_grade_audit_faculty FOREIGN KEY (faculty_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
