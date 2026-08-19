<?php
declare(strict_types=1);

namespace Codify;

use Codify\Controllers\AuthController;
use Codify\Controllers\AdministratorStudentAuditController;
use Codify\Controllers\AdministratorDataCleanupController;
use Codify\Controllers\AcademicStructureController;
use Codify\Controllers\AssessmentBankController;
use Codify\Controllers\FacultySubjectController;
use Codify\Controllers\FacultyTeachingController;
use Codify\Controllers\ProblemBankController;
use Codify\Controllers\FacultyStudentController;
use Codify\Controllers\FacultyManagementController;
use Codify\Controllers\SystemSettingController;
use Codify\Controllers\StudentLearningController;
use Codify\Controllers\StudentDeviceController;
use Codify\Controllers\UserController;
use Codify\Controllers\WorkspaceController;
use Codify\Core\Request;
use Codify\Core\Router;
use Codify\Repositories\SystemSettingRepository;
use Codify\Repositories\AdministratorAuditLogRepository;
use Codify\Repositories\AdministratorDataCleanupRepository;
use Codify\Repositories\AdministratorStudentAuditRepository;
use Codify\Repositories\StudentLearningRepository;
use Codify\Repositories\DeviceConsistencyRepository;
use Codify\Repositories\AcademicStructureRepository;
use Codify\Repositories\AssessmentBankRepository;
use Codify\Repositories\FacultyScopeRepository;
use Codify\Repositories\FacultyAdministrationRepository;
use Codify\Repositories\FacultyTeachingRepository;
use Codify\Repositories\ProblemBankRepository;
use Codify\Repositories\TokenRepository;
use Codify\Repositories\UserRepository;
use Codify\Services\AuthGuard;
use Codify\Services\AuthService;
use Codify\Services\CodeExecutionRateLimiter;
use Codify\Services\DeviceCredentialVerifier;
use Codify\Services\DeviceFingerprintService;
use Codify\Services\Judge0RunnerService;
use Codify\Services\LoginRateLimiter;
use Codify\Services\SyllabusStorageService;
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
        $deviceConsistency = new DeviceConsistencyRepository($this->db);
        $guard = new AuthGuard($tokens, $users, $settings, $deviceConsistency);
        $academic = new AcademicStructureRepository($this->db);
        $assessmentBanks = new AssessmentBankRepository($this->db);
        $scopes = new FacultyScopeRepository($this->db);
        $facultyAdministration = new FacultyAdministrationRepository($this->db);
        $teaching = new FacultyTeachingRepository($this->db);
        $problems = new ProblemBankRepository($this->db);
        $studentLearning = new StudentLearningRepository($this->db);
        $administratorStudentAudit = new AdministratorStudentAuditRepository($this->db);
        $administratorAuditLogs = new AdministratorAuditLogRepository($this->db);
        $administratorDataCleanup = new AdministratorDataCleanupRepository($this->db);
        $runnerConfig = require dirname(__DIR__) . '/config/runner.php';
        $runner = new Judge0RunnerService($runnerConfig);
        $syllabus = new SyllabusStorageService(require dirname(__DIR__) . '/config/syllabus.php');
        $executionLimiter = new CodeExecutionRateLimiter($this->db);
        $authService = new AuthService($users, $tokens, $settings, new LoginRateLimiter($this->db));
        $controllers = [
            'auth' => new AuthController($authService, $guard, $users, $tokens),
            'settings' => new SystemSettingController($settings, $guard),
            'workspace' => new WorkspaceController($guard, $users, $scopes, require dirname(__DIR__) . '/config/codify.php'),
            'users' => new UserController($users, $tokens, $settings, $guard),
            'students' => new FacultyStudentController($users, $scopes, $tokens, $settings, $guard),
            'faculty_subjects' => new FacultySubjectController($scopes, $guard),
            'assessment_bank' => new AssessmentBankController($assessmentBanks, $settings, $guard),
            'faculty_teaching' => new FacultyTeachingController($teaching, $users, $tokens, $settings, $guard, $syllabus),
            'problem_bank' => new ProblemBankController($problems, $settings, $guard),
            'student_learning' => new StudentLearningController($studentLearning, $settings, $guard, $executionLimiter, $runner, (int) $runnerConfig['rate_limit_per_minute'], $syllabus),
            'student_devices' => new StudentDeviceController($deviceConsistency, $settings, $tokens, $guard, new DeviceFingerprintService(), new DeviceCredentialVerifier()),
            'administrator_student_audit' => new AdministratorStudentAuditController($administratorStudentAudit, $administratorAuditLogs, $deviceConsistency, $tokens, $settings, $guard),
            'administrator_data_cleanup' => new AdministratorDataCleanupController($administratorDataCleanup, $guard, $syllabus),
            'faculty_management' => new FacultyManagementController($users, $scopes, $facultyAdministration, $tokens, $settings, $guard, $syllabus),
            'academic' => new AcademicStructureController($academic, $guard),
        ];
        $users->ensureInitialAdministrator();
        $router = new Router();
        $register = require dirname(__DIR__) . '/routes/api.php';
        $register($router, $controllers);
        $router->dispatch($request);
    }
}
