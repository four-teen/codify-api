<?php
declare(strict_types=1);

namespace Codify;

use Codify\Controllers\AuthController;
use Codify\Controllers\AcademicStructureController;
use Codify\Controllers\FacultySubjectController;
use Codify\Controllers\FacultyTeachingController;
use Codify\Controllers\ProblemBankController;
use Codify\Controllers\FacultyStudentController;
use Codify\Controllers\FacultyManagementController;
use Codify\Controllers\SystemSettingController;
use Codify\Controllers\StudentLearningController;
use Codify\Controllers\UserController;
use Codify\Controllers\WorkspaceController;
use Codify\Core\Request;
use Codify\Core\Router;
use Codify\Repositories\SystemSettingRepository;
use Codify\Repositories\StudentLearningRepository;
use Codify\Repositories\AcademicStructureRepository;
use Codify\Repositories\FacultyScopeRepository;
use Codify\Repositories\FacultyTeachingRepository;
use Codify\Repositories\ProblemBankRepository;
use Codify\Repositories\TokenRepository;
use Codify\Repositories\UserRepository;
use Codify\Services\AuthGuard;
use Codify\Services\AuthService;
use Codify\Services\CodeExecutionRateLimiter;
use Codify\Services\Judge0RunnerService;
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
        $academic = new AcademicStructureRepository($this->db);
        $scopes = new FacultyScopeRepository($this->db);
        $teaching = new FacultyTeachingRepository($this->db);
        $problems = new ProblemBankRepository($this->db);
        $studentLearning = new StudentLearningRepository($this->db);
        $runnerConfig = require dirname(__DIR__) . '/config/runner.php';
        $runner = new Judge0RunnerService($runnerConfig);
        $executionLimiter = new CodeExecutionRateLimiter($this->db);
        $authService = new AuthService($users, $tokens, $settings, new LoginRateLimiter($this->db));
        $controllers = [
            'auth' => new AuthController($authService, $guard, $users, $tokens),
            'settings' => new SystemSettingController($settings, $guard),
            'workspace' => new WorkspaceController($guard, $users, $scopes, require dirname(__DIR__) . '/config/codify.php'),
            'users' => new UserController($users, $tokens, $settings, $guard),
            'students' => new FacultyStudentController($users, $scopes, $tokens, $settings, $guard),
            'faculty_subjects' => new FacultySubjectController($scopes, $guard),
            'faculty_teaching' => new FacultyTeachingController($teaching, $users, $settings, $guard),
            'problem_bank' => new ProblemBankController($problems, $settings, $guard),
            'student_learning' => new StudentLearningController($studentLearning, $settings, $guard, $executionLimiter, $runner, (int) $runnerConfig['rate_limit_per_minute']),
            'faculty_management' => new FacultyManagementController($users, $scopes, $tokens, $settings, $guard),
            'academic' => new AcademicStructureController($academic, $guard),
        ];
        $users->ensureInitialAdministrator();
        $router = new Router();
        $register = require dirname(__DIR__) . '/routes/api.php';
        $register($router, $controllers);
        $router->dispatch($request);
    }
}
