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
