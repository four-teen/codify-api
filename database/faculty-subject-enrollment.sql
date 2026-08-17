SET NAMES utf8mb4;

ALTER TABLE student_profiles
    ADD COLUMN student_number VARCHAR(50) NULL AFTER program_id,
    ADD COLUMN gender VARCHAR(30) NULL AFTER student_number,
    ADD COLUMN mobile_number VARCHAR(30) NULL AFTER gender,
    ADD COLUMN course_label VARCHAR(255) NULL AFTER mobile_number,
    ADD COLUMN enrollment_status VARCHAR(100) NULL AFTER course_label,
    ADD UNIQUE KEY uq_student_profiles_number (student_number);

CREATE TABLE faculty_subjects (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    faculty_id BIGINT UNSIGNED NOT NULL,
    subject_id BIGINT UNSIGNED NOT NULL,
    section VARCHAR(100) NOT NULL DEFAULT '',
    class_schedule VARCHAR(255) NULL,
    academic_year VARCHAR(20) NOT NULL,
    academic_term VARCHAR(100) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_faculty_subject_term (faculty_id, subject_id, section, academic_year, academic_term),
    KEY idx_faculty_subjects_subject (subject_id),
    KEY idx_faculty_subjects_term (academic_year, academic_term),
    CONSTRAINT fk_faculty_subjects_faculty FOREIGN KEY (faculty_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_faculty_subjects_subject FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE faculty_subject_students (
    faculty_subject_id BIGINT UNSIGNED NOT NULL,
    student_id BIGINT UNSIGNED NOT NULL,
    source ENUM('manual', 'import') NOT NULL DEFAULT 'manual',
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (faculty_subject_id, student_id),
    KEY idx_faculty_subject_students_student (student_id),
    CONSTRAINT fk_faculty_subject_students_subject FOREIGN KEY (faculty_subject_id) REFERENCES faculty_subjects(id) ON DELETE CASCADE,
    CONSTRAINT fk_faculty_subject_students_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
