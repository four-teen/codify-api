<?php
declare(strict_types=1);

$self = basename(__FILE__);
$patterns = [
    'password variable assigned a fixed string' => '/\$[A-Za-z0-9_]*password[A-Za-z0-9_]*\s*=\s*[\'\"][^\'\"]+[\'\"]/i',
    'fixed string passed directly to password_hash' => '/password_hash\s*\(\s*[\'\"]/i',
    'fixed string passed directly as a login password' => '/->login\s*\([^,\r\n]+,\s*[\'\"]/i',
    'fixed string assigned to a password field' => '/[\'\"]password[\'\"]\s*=>\s*[\'\"]/i',
];
$violations = [];

foreach (glob(__DIR__ . DIRECTORY_SEPARATOR . '*-smoke.php') ?: [] as $file) {
    if (basename($file) === $self) continue;
    $lines = file($file, FILE_IGNORE_NEW_LINES);
    if (!is_array($lines)) throw new RuntimeException('Unable to inspect ' . basename($file) . '.');
    foreach ($lines as $index => $line) {
        foreach ($patterns as $description => $pattern) {
            if (preg_match($pattern, $line) === 1) $violations[] = basename($file) . ':' . ($index + 1) . ' — ' . $description;
        }
    }
}

if ($violations !== []) {
    throw new RuntimeException("Fixed test credentials are not allowed. Generate them with random_bytes() at runtime:\n" . implode("\n", $violations));
}

echo 'Test credential hygiene smoke test passed.' . PHP_EOL;
