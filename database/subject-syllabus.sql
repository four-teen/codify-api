SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS faculty_subject_syllabi (
    faculty_subject_id BIGINT UNSIGNED NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    stored_name VARCHAR(100) NOT NULL,
    mime_type VARCHAR(100) NOT NULL DEFAULT 'application/pdf',
    size_bytes BIGINT UNSIGNED NOT NULL,
    uploaded_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (faculty_subject_id),
    UNIQUE KEY uq_faculty_subject_syllabi_stored_name (stored_name),
    CONSTRAINT fk_faculty_subject_syllabi_subject FOREIGN KEY (faculty_subject_id) REFERENCES faculty_subjects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE faculty_subjects
SET section = UPPER(section),
    class_schedule = CASE WHEN class_schedule IS NULL THEN NULL ELSE UPPER(class_schedule) END;

SET FOREIGN_KEY_CHECKS = 1;
