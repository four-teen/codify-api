SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS coding_problem_work (
    student_id BIGINT UNSIGNED NOT NULL,
    problem_id BIGINT UNSIGNED NOT NULL,
    close_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
    status ENUM('ready', 'active', 'closed', 'locked', 'submitted') NOT NULL DEFAULT 'ready',
    session_token CHAR(64) NULL,
    last_seen_at DATETIME NULL,
    last_close_reason VARCHAR(40) NULL,
    submitted_code MEDIUMTEXT NULL,
    submitted_at DATETIME NULL,
    PRIMARY KEY (student_id, problem_id),
    CONSTRAINT fk_problem_work_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_problem_work_problem FOREIGN KEY (problem_id) REFERENCES coding_problems(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS coding_problem_work_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    student_id BIGINT UNSIGNED NOT NULL,
    problem_id BIGINT UNSIGNED NOT NULL,
    reason VARCHAR(40) NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_problem_work_events (student_id, problem_id),
    CONSTRAINT fk_problem_work_events_work FOREIGN KEY (student_id, problem_id) REFERENCES coding_problem_work(student_id, problem_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
