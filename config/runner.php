<?php
declare(strict_types=1);

return [
    'enabled' => filter_var(env('JUDGE0_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN),
    'api_url' => rtrim((string) env('JUDGE0_API_URL', ''), '/'),
    'api_key' => trim((string) env('JUDGE0_API_KEY', '')),
    'api_host' => trim((string) env('JUDGE0_API_HOST', '')),
    'auth_token' => trim((string) env('JUDGE0_AUTH_TOKEN', '')),
    'python_language_id' => max(1, (int) env('JUDGE0_PYTHON_LANGUAGE_ID', '109')),
    'connect_timeout_seconds' => max(2, min(10, (int) env('JUDGE0_CONNECT_TIMEOUT_SECONDS', '5'))),
    'request_timeout_seconds' => max(5, min(30, (int) env('JUDGE0_REQUEST_TIMEOUT_SECONDS', '15'))),
    'rate_limit_per_minute' => max(1, min(30, (int) env('CODE_RUN_RATE_LIMIT_PER_MINUTE', '10'))),
];
