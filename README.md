# Codify API — plain PHP

Framework-free PHP API for **Codify — Learn. Code. Verify.** It reproduces the implemented xCodify API features with a small MVC-style architecture suitable for ordinary cPanel hosting.

## Requirements

- PHP 7.1 or newer
- MySQL 5.7+/MariaDB 10.3+
- PDO MySQL, JSON, and Apache `mod_rewrite`

## Local setup

1. Copy `.env.example` to `.env` and set the database and initial administrator values.
2. Import `database/schema.sql`.
3. Visit `http://localhost/codify-api/health` and `http://localhost/codify-api/api/v1`.
4. The first request creates the configured initial administrator if that username and email do not already exist.

The administrator starts with `must_change_password=true`. Remove `CODIFY_INITIAL_ADMIN_PASSWORD` from production configuration after the account has been created.

## cPanel deployment

1. Create a database and user in cPanel, then import `database/cpanel-schema.sql` in phpMyAdmin.
2. Upload this project to the API subdomain document root.
3. Create `.env` from `.env.cpanel.example` and use the exact prefixed database/user names shown in cPanel.
4. Set `FRONTEND_ORIGINS` to the exact HTTPS frontend origins, comma-separated.
5. Keep `APP_DEBUG=false`, enable SSL, and verify `/health` before testing login.

## Implemented endpoints

- Public health and public system settings
- Bearer-token login, logout, current user, and password change
- Role-protected administrator, faculty, and student workspaces
- Administrator user management and system settings
- Faculty-owned student management

The API uses prepared PDO statements, Bcrypt passwords, hashed expiring tokens, login throttling, strict role/ownership checks, maintenance and password gates, CORS allowlisting, validation, and security headers.

## Student device consistency

Existing installations should import `database/device-consistency.sql` once, then enable device consistency from administrator system settings. Prefer setting `DEVICE_FINGERPRINT_KEY` to a stable private random value of at least 32 characters. If it is missing or left as a placeholder, Codify securely creates `storage/device-fingerprint.key`; the storage directory must be writable and that generated file must be preserved across deployments. The setting is disabled by default and student acknowledgment is required by default.

The feature stores student-scoped HMAC signatures of coarse browser characteristics and public P-256 device keys. Browser private keys remain non-exportable in IndexedDB. When a limited mobile or embedded browser cannot retain a protected P-256 key, Codify records the same privacy-conscious browser signals in a clearly labeled compatibility mode; it never presents that lower-assurance record as cryptographically verified. It does not collect biometric fingerprints, precise location, browsing history, raw IP addresses, or student files.

When acknowledgment is required, student workspace endpoints remain locked until the current policy notice is accepted. Declining records the decision and revokes the current login session; after acceptance, collection continues in the background without a student-facing device-verification dashboard.

Useful validation commands are `php tests/unit-smoke.php`, `php tests/device-repository-smoke.php`, and `php tests/device-api-smoke.php`.

## Quiz attempts and faculty retakes

Existing installations should import `database/student-assessment-attempts.sql` once. Quizzes allow one student submission by default. A faculty instructor can grant one additional submission at a time to an individual student from the subject roster; the API enforces the limit even when requests are sent outside the browser interface.

Run `php tests/quiz-retake-smoke.php` to validate quiz locking and faculty retake eligibility against an existing submitted quiz when one is available.

## Faculty subject attendance

Existing installations must import `database/subject-attendance.sql` before deploying the attendance API. The migration adds only `subject_attendance_sessions` and `subject_attendance_records`; both full schemas include these tables for new installations.

Faculty open a subject's Attendance page, choose today or an earlier date, check present students, and save. There is one sheet per subject offering and calendar date. Opening a date never creates records. Dates use the institution's configured timezone; the class date is stored independently from entry timestamps. An unchecked student becomes absent only when the entire sheet is saved. Attendance does not change gradebook calculations.

The faculty-only API requires ownership of the subject offering. `GET /api/v1/faculty/subject-offerings/{offering}/attendance?date=YYYY-MM-DD` returns a sheet; `PUT` to the same path takes `date`, `revision` (0 for a new sheet), optional `notes`, and all `entries` as `{student_id, status}` (`present` or `absent`). Saves are transactional, reject changed rosters/foreign students, and return 409 for stale revisions. The database enforces one date per offering.

`GET /api/v1/faculty/subject-offerings/{offering}/attendance/history` returns saved dates and student totals (`present`, `absent`, `recorded`, `percentage`, and date/status mappings). These totals are available for later gradebook integration; no grading weights or formulas are applied now. Percentages use each student's recorded sessions only. Saved sheets retain student identifiers/names after roster or student-account removal; deleting the subject offering removes its attendance. New sheets use the current roster, including when entering dates before the class list was imported. Saved sheets retain their original student list. This version records one attendance per class per day; separate lecture/lab sessions and previous-term subject navigation are not included.

Run `php tests/subject-attendance-smoke.php` for rollback-only repository and permission checks, and `php tests/subject-attendance-api-smoke.php` against the local API for HTTP tests with temporary fixtures that are removed afterward.

## Faculty subject gradebook

Existing installations should import `database/subject-gradebook.sql` once before deploying the gradebook API. New installations receive the same additive tables through either full schema. The migration does not alter quiz banks, questions, student submissions, attempt limits, or retake permissions.

Each subject receives editable Midterm and Final Term category weights. Existing quizzes, exams, and coding activities assigned to the subject are offered for explicit faculty selection and do not count toward grades until added to a category. Quiz and exam results are read from the existing final attempt average, remain read-only in the gradebook, and cannot be overridden through the gradebook API. Custom/offline activities and manually entered scores are stored separately from student submissions. Coding activities remain manually scored until finalized coding results are persisted by the application.

Grades use `40 + (raw percentage × 60%)` within each category, weighted term totals, and `(Midterm + Final Term) / 2` for the overall final score. Draft, published, and locked term states are available; a term cannot be locked while grades are incomplete.

Run `php tests/subject-gradebook-smoke.php` to validate default weights, Base-40 calculations, faculty scoring, and quiz-attempt isolation against an existing subject and enrolled student when available.

## Administrator student audit

Existing installations that already imported the device-consistency migration should also import `database/administrator-student-audit.sql` and `database/student-login-events.sql` once. Import the login-events migration before deploying API code that records successful student authentication. New installations receive these tables through either full schema.

Administrator-only endpoints under `/api/v1/admin/student-audit` provide the complete student directory, last-name/email/student-ID/database-ID search, per-student device-verification and login-event dashboards, session revocation, recorded-login cleanup, individual device removal, and full recognized-device reset. Successful student authentication is recorded independently of browser device-key support, while a matching device verification is linked when available. Successful-login history uses the configured device-retention period and expired events are pruned during student authentication. Cleanup operations are recorded in a separate administrator audit trail that is not erased with device data.

Run `php tests/administrator-student-audit-api-smoke.php` to validate the directory, authorization, searches, dashboard, login cleanup, and recognized-device reset against temporary accounts. Run `php tests/test-credential-hygiene-smoke.php` before committing to ensure smoke tests generate credentials at runtime instead of storing password literals that secret scanners can flag.

## Administrator faculty cleanup

Administrator-only endpoints under `/api/v1/admin/faculty/{faculty}` expose a faculty dashboard with subjects, owned students, aggregate records, and paginated recorded activity. Administrators can delete all owned student accounts, all faculty subject offerings, one subject offering, or the faculty account with all related data.

Subject deletion cascades rosters, syllabi, and subject links. Coding problems and assessment banks used only by the deleted subject are removed with their tests or questions; content shared with another subject is preserved. Student accounts are deleted only by the explicit all-students or full-faculty operations.
