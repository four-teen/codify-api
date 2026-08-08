<?php
declare(strict_types=1);

namespace Codify\Core;

final class Router
{
    /** @var array */
    private $routes = [];

    public function add(string $method, string $pattern, callable $handler): void { $this->routes[] = [strtoupper($method), $pattern, $handler]; }
    public function get(string $pattern, callable $handler): void { $this->add('GET', $pattern, $handler); }
    public function post(string $pattern, callable $handler): void { $this->add('POST', $pattern, $handler); }
    public function patch(string $pattern, callable $handler): void { $this->add('PATCH', $pattern, $handler); }
    public function delete(string $pattern, callable $handler): void { $this->add('DELETE', $pattern, $handler); }

    public function dispatch(Request $request): void
    {
        foreach ($this->routes as [$method, $pattern, $handler]) {
            if ($method !== $request->method()) continue;
            $parameterNames = [];
            $segments = array_values(array_filter(explode('/', trim($pattern, '/')), 'strlen'));
            $regexSegments = [];
            foreach ($segments as $segment) {
                if (preg_match('/^\{([A-Za-z_][A-Za-z0-9_]*)\}$/', $segment, $matches) === 1) {
                    $parameterNames[] = $matches[1];
                    $regexSegments[] = '([^/]+)';
                } else {
                    $regexSegments[] = preg_quote($segment, '#');
                }
            }
            $regex = $regexSegments === [] ? '#^/$#' : '#^/' . implode('/', $regexSegments) . '$#';
            if (preg_match($regex, $request->path(), $matches) !== 1) continue;
            array_shift($matches);
            $request->setRouteParameters(array_combine($parameterNames, $matches) ?: []);
            $handler($request);
            return;
        }
        throw new HttpException(404, 'Route not found.');
    }
}
