CREATE TABLE IF NOT EXISTS student_device_sessions (
    student_id BIGINT UNSIGNED NOT NULL,
    device_id BIGINT UNSIGNED NOT NULL,
    access_token_id BIGINT UNSIGNED NOT NULL,
    first_verified_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_verified_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (device_id, access_token_id),
    KEY idx_device_sessions_student (student_id),
    KEY idx_device_sessions_token (access_token_id),
    CONSTRAINT fk_device_sessions_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_device_sessions_device FOREIGN KEY (device_id) REFERENCES student_devices(id) ON DELETE CASCADE,
    CONSTRAINT fk_device_sessions_token FOREIGN KEY (access_token_id) REFERENCES personal_access_tokens(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO student_device_sessions (student_id, device_id, access_token_id, first_verified_at, last_verified_at)
SELECT student_id, device_id, access_token_id, MIN(occurred_at), MAX(occurred_at)
FROM student_device_events
WHERE access_token_id IS NOT NULL
GROUP BY student_id, device_id, access_token_id
ON DUPLICATE KEY UPDATE last_verified_at = VALUES(last_verified_at);

CREATE TABLE IF NOT EXISTS administrator_student_audit_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    actor_user_id BIGINT UNSIGNED NULL,
    subject_user_id BIGINT UNSIGNED NULL,
    action VARCHAR(120) NOT NULL,
    summary VARCHAR(255) NOT NULL,
    metadata TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_admin_student_audit_subject_time (subject_user_id, created_at),
    KEY idx_admin_student_audit_actor_time (actor_user_id, created_at),
    KEY idx_admin_student_audit_action_time (action, created_at),
    CONSTRAINT fk_admin_student_audit_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_admin_student_audit_subject FOREIGN KEY (subject_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
