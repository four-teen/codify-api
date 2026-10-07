# Local versus live database schema — 7 October 2026

Compared the actual local `codify_db` (MySQL 5.7.25) using read-only SHOW CREATE TABLE queries against `nshft34_codify_db_live.sql` (MariaDB 10.3.39, exported 6 October 2026). The export is a snapshot; the current live server was not queried. No database was changed.

## Findings

- Live export: 43 tables. Local database: 48 tables.
- Five new local tables; all 43 existing tables match structurally.
- No altered existing columns, indexes, foreign keys, engines, or effective table collations.
- No live tables are missing locally.
- Row data and AUTO_INCREMENT counters were excluded. Equivalent MySQL/MariaDB formatting was normalized: integer display widths, SQL keyword case, numeric default quotes, CURRENT_TIMESTAMP syntax, inherited utf8mb4_unicode_ci column collation, and implicit NULL defaults on text columns.

| New table | Purpose | Columns |
| --- | --- | --- |
| coding_problem_work | Student attempt state and final code submission | student_id, problem_id, close_count, status, session_token, last_seen_at, last_close_reason, submitted_code, submitted_at |
| coding_problem_work_events | Attempt closure/event history | id, student_id, problem_id, reason, created_at |
| coding_rubric_templates | Faculty rubric templates | id, faculty_id, name, description, criteria_json, created_at, updated_at |
| coding_problem_rubrics | Rubric assigned to a coding problem | problem_id, template_id, name, description, criteria_json, updated_at |
| coding_problem_evaluations | Student rubric snapshots, scores, and feedback | student_id, problem_id, rubric_json, instructions_snapshot, initial_json, final_json, final_score, feedback, graded_by, graded_at |

## SQL files needed for deployment

Apply to the live database in this order, before deploying the new backend:

1. `database/python-written-attempts.sql` — creates coding_problem_work and coding_problem_work_events.
2. `database/python-rubrics.sql` — creates coding_rubric_templates, coding_problem_rubrics, and coding_problem_evaluations. Evaluations reference coding_problem_work, so the order matters.

These existing branch SQL files define the five new tables with the columns and relationships present locally. Both use CREATE TABLE IF NOT EXISTS and contain no INSERT, UPDATE, DELETE, DROP, or alterations to the existing 43 tables. No full local database import is needed. These two SQL files are currently untracked and must be included in the deployment source.

This check establishes the schema delta; it does not execute the migrations on MariaDB or validate the complete application deployment.
