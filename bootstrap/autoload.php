<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/env.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'Codify\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $relative = str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen($prefix)));
    $path = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . $relative . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

$timezone = env('APP_TIMEZONE', 'Asia/Manila') ?: 'Asia/Manila';
if (in_array($timezone, timezone_identifiers_list(), true)) {
    date_default_timezone_set($timezone);
}
