<?php
declare(strict_types=1);

namespace Codify\Core;

final class Response
{
    public static function json(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header("Permissions-Policy: camera=(), microphone=(), geolocation=()");
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function success(array $data = [], string $message = '', int $status = 200): void
    {
        $payload = ['success' => true, 'data' => $data];
        if ($message !== '') $payload['message'] = $message;
        self::json($payload, $status);
    }

    public static function error(HttpException $exception): void
    {
        $payload = ['success' => false, 'message' => $exception->getMessage()];
        if ($exception->errorCode !== null) $payload['code'] = $exception->errorCode;
        if ($exception->errors !== []) $payload['errors'] = $exception->errors;
        self::json($payload, $exception->status);
    }
}
