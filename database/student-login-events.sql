CREATE TABLE IF NOT EXISTS student_login_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    student_id BIGINT UNSIGNED NOT NULL,
    access_token_id BIGINT UNSIGNED NULL,
    source_device_event_id BIGINT UNSIGNED NULL,
    occurred_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_student_login_token (access_token_id),
    UNIQUE KEY uq_student_login_device_event (source_device_event_id),
    KEY idx_student_login_events_student_time (student_id, occurred_at),
    KEY idx_student_login_events_time (occurred_at),
    CONSTRAINT fk_student_login_events_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_student_login_events_token FOREIGN KEY (access_token_id) REFERENCES personal_access_tokens(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @student_login_time_index_exists = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'student_login_events' AND INDEX_NAME = 'idx_student_login_events_time'
);
SET @student_login_time_index_sql = IF(
    @student_login_time_index_exists = 0,
    'ALTER TABLE student_login_events ADD INDEX idx_student_login_events_time (occurred_at)',
    'SELECT 1'
);
PREPARE student_login_time_index_statement FROM @student_login_time_index_sql;
EXECUTE student_login_time_index_statement;
DEALLOCATE PREPARE student_login_time_index_statement;

INSERT IGNORE INTO student_login_events (student_id, access_token_id, source_device_event_id, occurred_at)
SELECT student_id, access_token_id, id, occurred_at
FROM student_device_events
WHERE event_type = 'session_start';
