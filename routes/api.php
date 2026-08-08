<?php
declare(strict_types=1);

use Codify\Core\Request;
use Codify\Core\Response;

return static function ($router, array $controllers): void {
    $prefix = '/api/v1';
    $router->get($prefix, static function (Request $request): void {
        Response::success(['name' => 'Codify API', 'version' => 'v1', 'status' => 'online']);
    });
    $router->get($prefix . '/system-settings/public', [$controllers['settings'], 'publicShow']);
    $router->post($prefix . '/auth/login', [$controllers['auth'], 'login']);
    $router->get($prefix . '/auth/me', [$controllers['auth'], 'me']);
    $router->post($prefix . '/auth/logout', [$controllers['auth'], 'logout']);
    $router->patch($prefix . '/auth/password', [$controllers['auth'], 'changePassword']);

    foreach (['student', 'faculty', 'administrator'] as $role) {
        $router->get($prefix . '/' . $role . '/workspace', static function (Request $request) use ($controllers, $role): void {
            $controllers['workspace']->show($request, $role);
        });
    }

    $router->get($prefix . '/system-settings', [$controllers['settings'], 'show']);
    $router->patch($prefix . '/system-settings', [$controllers['settings'], 'update']);

    $router->get($prefix . '/users', [$controllers['users'], 'index']);
    $router->post($prefix . '/users', [$controllers['users'], 'store']);
    $router->get($prefix . '/users/{user}', [$controllers['users'], 'show']);
    $router->patch($prefix . '/users/{user}', [$controllers['users'], 'update']);
    $router->delete($prefix . '/users/{user}', [$controllers['users'], 'destroy']);

    $router->get($prefix . '/faculty/students', [$controllers['students'], 'index']);
    $router->post($prefix . '/faculty/students', [$controllers['students'], 'store']);
    $router->get($prefix . '/faculty/students/{student}', [$controllers['students'], 'show']);
    $router->patch($prefix . '/faculty/students/{student}', [$controllers['students'], 'update']);
    $router->delete($prefix . '/faculty/students/{student}', [$controllers['students'], 'destroy']);
};
