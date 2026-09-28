-- Additive migration. Run once before deploying the attendance API.
CREATE TABLE IF NOT EXISTS subject_attendance_sessions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    faculty_subject_id BIGINT UNSIGNED NOT NULL,
    attendance_date DATE NOT NULL,
    notes VARCHAR(500) NOT NULL DEFAULT '',
    revision INT UNSIGNED NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED NOT NULL,
    updated_by BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_subject_attendance_date (faculty_subject_id, attendance_date),
    CONSTRAINT fk_attendance_session_subject FOREIGN KEY (faculty_subject_id) REFERENCES faculty_subjects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS subject_attendance_records (
    session_id BIGINT UNSIGNED NOT NULL,
    -- Historical identifiers and names intentionally survive roster/account removal.
    student_id BIGINT UNSIGNED NOT NULL,
    student_number VARCHAR(100) NOT NULL DEFAULT '',
    student_name VARCHAR(255) NOT NULL,
    status ENUM('present', 'absent') NOT NULL,
    PRIMARY KEY (session_id, student_id),
    KEY idx_attendance_student (student_id),
    CONSTRAINT fk_attendance_record_session FOREIGN KEY (session_id) REFERENCES subject_attendance_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
