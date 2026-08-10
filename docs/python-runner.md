# Codify Python runner

Codify executes student Python through a separate Judge0 service. PHP and cPanel never execute student source code directly.

## Recommended production deployment

1. Deploy Judge0 CE on a separate Linux VPS or institution-managed server using the official project instructions: https://github.com/judge0/judge0
2. Put the runner behind HTTPS and require an authentication token.
3. Keep the runner private to the Codify API where possible. Do not expose its management endpoints publicly.
4. Confirm the Python language ID through the runner's `GET /languages` endpoint.
5. Import `database/python-runner.sql` into the Codify database.
6. Add the following values to the API `.env` and restart PHP/clear any hosting cache:

```dotenv
JUDGE0_ENABLED=true
JUDGE0_API_URL=https://runner.example.edu
JUDGE0_AUTH_TOKEN=replace-with-runner-auth-token
JUDGE0_PYTHON_LANGUAGE_ID=109
JUDGE0_CONNECT_TIMEOUT_SECONDS=5
JUDGE0_REQUEST_TIMEOUT_SECONDS=15
CODE_RUN_RATE_LIMIT_PER_MINUTE=10
```

The language ID depends on the runtimes installed on the selected Judge0 server. `109` is only the configured default and must be verified against that server.

## Optional hosted Judge0 configuration

Use this only after the institution approves sending student source code and program input to a third-party processor.

```dotenv
JUDGE0_ENABLED=true
JUDGE0_API_URL=https://judge0-ce.p.rapidapi.com
JUDGE0_API_KEY=replace-with-rapidapi-key
JUDGE0_API_HOST=judge0-ce.p.rapidapi.com
JUDGE0_AUTH_TOKEN=
JUDGE0_PYTHON_LANGUAGE_ID=109
```

Never place the API key or authentication token in `codify-web`, Vercel environment variables used by the frontend build, or browser JavaScript.

## Safeguards implemented in Codify

- Student authentication and mandatory first-password change
- Enrollment check for the selected problem
- 60,000-character source limit and 20,000-character input limit
- Per-student and per-IP run rate limiting
- Problem-specific CPU and memory limits with server-side maximums
- Network access disabled in Judge0 execution requests
- Process, file, request-timeout, and output-size limits
- Runner credentials remain in the PHP API environment
- Hidden tests and reference solutions are never included in Run Code requests

Run Code is an exploratory execution feature. Graded submissions and hidden-test evaluation should be implemented as a separate server-side workflow with persistent attempt records.
