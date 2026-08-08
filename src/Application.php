<?php
declare(strict_types=1);

namespace Codify;

use Codify\Controllers\AuthController;
use Codify\Controllers\FacultyStudentController;
use Codify\Controllers\SystemSettingController;
use Codify\Controllers\UserController;
use Codify\Controllers\WorkspaceController;
use Codify\Core\Request;
use Codify\Core\Router;
use Codify\Repositories\SystemSettingRepository;
use Codify\Repositories\TokenRepository;
use Codify\Repositories\UserRepository;
use Codify\Services\AuthGuard;
use Codify\Services\AuthService;
use Codify\Services\LoginRateLimiter;
use PDO;

final class Application
{
    /** @var PDO */
    private $db;
    public function __construct(PDO $db) { $this->db = $db; }

    public function handle(Request $request): void
    {
        $settings = new SystemSettingRepository($this->db);
        $tokens = new TokenRepository($this->db);
        $users = new UserRepository($this->db);
        $guard = new AuthGuard($tokens, $users, $settings);
        $authService = new AuthService($users, $tokens, $settings, new LoginRateLimiter($this->db));
        $controllers = [
            'auth' => new AuthController($authService, $guard, $users, $tokens),
            'settings' => new SystemSettingController($settings, $guard),
            'workspace' => new WorkspaceController($guard, $users, require dirname(__DIR__) . '/config/codify.php'),
            'users' => new UserController($users, $tokens, $settings, $guard),
            'students' => new FacultyStudentController($users, $tokens, $settings, $guard),
        ];
        $users->ensureInitialAdministrator();
        $router = new Router();
        $register = require dirname(__DIR__) . '/routes/api.php';
        $register($router, $controllers);
        $router->dispatch($request);
    }
}
