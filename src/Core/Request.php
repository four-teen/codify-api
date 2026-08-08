<?php
declare(strict_types=1);

namespace Codify\Core;

final class Request
{
    /** @var string */
    private $method;
    /** @var string */
    private $path;
    /** @var array */
    private $query;
    /** @var array */
    private $routeParameters = [];
    /** @var array|null */
    private $json = null;

    private function __construct(string $method, string $path, array $query)
    {
        $this->method = strtoupper($method);
        $this->path = $path;
        $this->query = $query;
    }

    public static function capture(): self
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $scriptDirectory = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
        if ($scriptDirectory !== '' && $scriptDirectory !== '/' && strpos($path, $scriptDirectory) === 0) {
            $path = substr($path, strlen($scriptDirectory)) ?: '/';
        }
        $path = '/' . ltrim($path, '/');
        $path = rtrim($path, '/') ?: '/';
        return new self($method, $path, $_GET);
    }

    public function method(): string { return $this->method; }
    public function path(): string { return $this->path; }
    public function query(string $key, $default = null) { return array_key_exists($key, $this->query) ? $this->query[$key] : $default; }

    public function json(): array
    {
        if ($this->json !== null) return $this->json;
        $raw = file_get_contents('php://input') ?: '';
        if ($raw === '') return $this->json = [];
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
            throw new HttpException(400, 'A valid JSON request body is required.');
        }
        return $this->json = $decoded;
    }

    public function bearerToken(): string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        return preg_match('/^Bearer\s+(.+)$/i', trim((string) $header), $matches) === 1 ? trim($matches[1]) : '';
    }

    public function ip(): string { return (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'); }
    public function setRouteParameters(array $parameters): void { $this->routeParameters = $parameters; }
    public function route(string $key, $default = null) { return $this->routeParameters[$key] ?? $default; }
}
