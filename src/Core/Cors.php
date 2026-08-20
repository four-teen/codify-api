<?php
declare(strict_types=1);

namespace Codify\Core;

final class Cors
{
    public static function apply(): void
    {
        $origin = trim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));
        $configured = env('FRONTEND_ORIGINS', env('FRONTEND_URL', '')) ?: '';
        $allowed = array_values(array_filter(array_map(static function (string $value): string {
            return rtrim(trim($value), '/');
        }, explode(',', $configured))));
        if ($origin !== '' && in_array(rtrim($origin, '/'), $allowed, true)) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Vary: Origin');
            header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
            header('Access-Control-Allow-Headers: Accept, Authorization, Content-Type, Origin');
            header('Access-Control-Expose-Headers: Content-Disposition, Content-Length');
            header('Access-Control-Max-Age: 600');
        }
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
            http_response_code($origin !== '' && in_array(rtrim($origin, '/'), $allowed, true) ? 204 : 403);
            exit;
        }
    }
}
