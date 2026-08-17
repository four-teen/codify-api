SET @device_settings_exist = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'system_settings' AND COLUMN_NAME = 'device_consistency_enabled'
);
SET @device_settings_sql = IF(
    @device_settings_exist = 0,
    'ALTER TABLE system_settings ADD COLUMN device_consistency_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER max_failed_login_attempts, ADD COLUMN device_consent_required TINYINT(1) NOT NULL DEFAULT 1 AFTER device_consistency_enabled, ADD COLUMN device_retention_days SMALLINT UNSIGNED NOT NULL DEFAULT 90 AFTER device_consent_required, ADD COLUMN device_policy_version VARCHAR(40) NOT NULL DEFAULT ''1.0'' AFTER device_retention_days, ADD COLUMN device_notice TEXT NULL AFTER device_policy_version',
    'SELECT 1'
);
PREPARE device_settings_statement FROM @device_settings_sql;
EXECUTE device_settings_statement;
DEALLOCATE PREPARE device_settings_statement;

UPDATE system_settings
SET device_notice = COALESCE(device_notice, 'Codify records a privacy-conscious device signature and a browser-held security key to show whether this account is being used from a recognized or new device. Raw fingerprints, precise location, browsing history, and files are not collected.')
WHERE id = 1;

CREATE TABLE IF NOT EXISTS student_device_consents (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    student_id BIGINT UNSIGNED NOT NULL,
    policy_version VARCHAR(40) NOT NULL,
    action ENUM('granted', 'withdrawn') NOT NULL,
    notice_hash CHAR(64) NOT NULL,
    network_hash CHAR(64) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_device_consents_student_time (student_id, created_at),
    CONSTRAINT fk_device_consents_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS student_devices (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    student_id BIGINT UNSIGNED NOT NULL,
    credential_id VARCHAR(100) NOT NULL,
    public_key_jwk TEXT NOT NULL,
    fingerprint_hash CHAR(64) NOT NULL,
    component_hashes TEXT NOT NULL,
    device_label VARCHAR(120) NOT NULL,
    browser_label VARCHAR(120) NOT NULL,
    os_label VARCHAR(120) NOT NULL,
    device_type ENUM('desktop', 'tablet', 'mobile', 'unknown') NOT NULL DEFAULT 'unknown',
    status ENUM('recognized', 'new', 'revoked', 'reported') NOT NULL DEFAULT 'new',
    first_seen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_verified_at TIMESTAMP NULL,
    seen_count INT UNSIGNED NOT NULL DEFAULT 1,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_student_devices_credential (credential_id),
    KEY idx_student_devices_student_status (student_id, status),
    KEY idx_student_devices_student_fingerprint (student_id, fingerprint_hash),
    CONSTRAINT fk_student_devices_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS student_device_challenges (
    id CHAR(64) NOT NULL,
    student_id BIGINT UNSIGNED NOT NULL,
    device_id BIGINT UNSIGNED NOT NULL,
    access_token_id BIGINT UNSIGNED NOT NULL,
    challenge VARCHAR(100) NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    used_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_device_challenges_expiry (expires_at),
    CONSTRAINT fk_device_challenges_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_device_challenges_device FOREIGN KEY (device_id) REFERENCES student_devices(id) ON DELETE CASCADE,
    CONSTRAINT fk_device_challenges_token FOREIGN KEY (access_token_id) REFERENCES personal_access_tokens(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS student_device_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    student_id BIGINT UNSIGNED NOT NULL,
    device_id BIGINT UNSIGNED NOT NULL,
    access_token_id BIGINT UNSIGNED NULL,
    event_type ENUM('session_start', 'device_recognized', 'device_revoked', 'device_reported') NOT NULL DEFAULT 'session_start',
    match_status ENUM('first_seen', 'recognized', 'new', 'changed', 'revoked', 'reported') NOT NULL,
    changed_components TEXT NULL,
    network_hash CHAR(64) NULL,
    occurred_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_device_session_event (access_token_id, device_id, event_type),
    KEY idx_device_events_student_time (student_id, occurred_at),
    KEY idx_device_events_device_time (device_id, occurred_at),
    CONSTRAINT fk_device_events_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_device_events_device FOREIGN KEY (device_id) REFERENCES student_devices(id) ON DELETE CASCADE,
    CONSTRAINT fk_device_events_token FOREIGN KEY (access_token_id) REFERENCES personal_access_tokens(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
