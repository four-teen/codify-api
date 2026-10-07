CREATE TABLE IF NOT EXISTS coding_rubric_templates (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    faculty_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(150) NOT NULL,
    description TEXT NULL,
    criteria_json MEDIUMTEXT NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    KEY idx_rubric_template_faculty (faculty_id),
    FOREIGN KEY (faculty_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS coding_problem_rubrics (
    problem_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    template_id BIGINT UNSIGNED NULL,
    name VARCHAR(150) NOT NULL,
    description TEXT NULL,
    criteria_json MEDIUMTEXT NOT NULL,
    updated_at DATETIME NOT NULL,
    FOREIGN KEY (problem_id) REFERENCES coding_problems(id) ON DELETE CASCADE,
    FOREIGN KEY (template_id) REFERENCES coding_rubric_templates(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS coding_problem_evaluations (
    student_id BIGINT UNSIGNED NOT NULL,
    problem_id BIGINT UNSIGNED NOT NULL,
    rubric_json MEDIUMTEXT NULL,
    instructions_snapshot MEDIUMTEXT NULL,
    initial_json MEDIUMTEXT NULL,
    final_json MEDIUMTEXT NULL,
    final_score DECIMAL(10,2) NULL,
    feedback TEXT NULL,
    graded_by BIGINT UNSIGNED NULL,
    graded_at DATETIME NULL,
    PRIMARY KEY (student_id, problem_id),
    FOREIGN KEY (student_id, problem_id) REFERENCES coding_problem_work(student_id, problem_id) ON DELETE CASCADE,
    FOREIGN KEY (graded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
