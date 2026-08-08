<?php
declare(strict_types=1);

use Codify\Application;
use Codify\Core\Connection;
use Codify\Core\Cors;
use Codify\Core\HttpException;
use Codify\Core\Request;
use Codify\Core\Response;

require_once dirname(__DIR__) . '/bootstrap/autoload.php';

Cors::apply();
$request = Request::capture();

try {
    if ($request->method() === 'GET' && in_array($request->path(), ['/', '/health', '/api/v1/health'], true)) {
        Response::success(['status' => 'healthy', 'service' => 'codify-api', 'timestamp' => date(DATE_ATOM)]);
    }
    $database = Connection::make();
    (new Application($database))->handle($request);
} catch (HttpException $exception) {
    Response::error($exception);
} catch (Throwable $exception) {
    error_log($exception->getMessage());
    $payload = ['success' => false, 'message' => 'Unexpected server error.'];
    if (filter_var(env('APP_DEBUG', 'false'), FILTER_VALIDATE_BOOLEAN)) $payload['detail'] = $exception->getMessage();
    Response::json($payload, 500);
}
