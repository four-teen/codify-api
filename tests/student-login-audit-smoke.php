<?php
declare(strict_types=1);

use Codify\Core\Connection;
use Codify\Core\HttpException;
use Codify\Repositories\AdministratorStudentAuditRepository;
use Codify\Repositories\StudentLoginEventRepository;
use Codify\Repositories\SystemSettingRepository;
use Codify\Repositories\TokenRepository;
use Codify\Repositories\UserRepository;
use Codify\Services\AuthService;
use Codify\Services\LoginRateLimiter;

require dirname(__DIR__) . '/bootstrap/autoload.php';

$db = Connection::make();
$studentId = 0;

try {
    $suffix = bin2hex(random_bytes(5));
    $username = 'manual-login-' . $suffix;
    $email = $username . '@example.test';
    $password = 'temporary-test-password-123';
    $statement = $db->prepare('INSERT INTO users (faculty_id, first_name, last_name, name, username, email, password, role, is_active, must_change_password, created_at, updated_at) VALUES (NULL, \'Manual\', \'Login Test\', \'Manual Login Test\', :username, :email, :password, \'student\', 1, 0, NOW(), NOW())');
    $statement->execute(['username' => $username, 'email' => $email, 'password' => password_hash($password, PASSWORD_BCRYPT)]);
    $studentId = (int) $db->lastInsertId();

    $events = new StudentLoginEventRepository($db);
    $db->prepare('INSERT INTO student_login_events (student_id, occurred_at) VALUES (:student, DATE_SUB(NOW(), INTERVAL 366 DAY))')->execute(['student' => $studentId]);
    $auth = new AuthService(new UserRepository($db), new TokenRepository($db), new SystemSettingRepository($db), new LoginRateLimiter($db), $events);
    $auth->login($username, $password, '127.0.0.1');
    $auth->login($email, $password, '127.0.0.1');

    try {
        $auth->login($username, 'incorrect-password', '127.0.0.1');
        throw new RuntimeException('Invalid student credentials unexpectedly succeeded.');
    } catch (HttpException $exception) {
        if ($exception->status !== 401) throw $exception;
    }

    if ($events->count($studentId) !== 2 || count($events->recent($studentId)) !== 2) {
        throw new RuntimeException('Successful logins were not recorded or expired login history was not pruned.');
    }

    $directory = (new AdministratorStudentAuditRepository($db))->paginatedStudents($email, 1, 25);
    $record = $directory['data'][0] ?? [];
    if ((int) ($record['id'] ?? 0) !== $studentId || (int) ($record['login_events'] ?? 0) !== 2 || empty($record['latest_login_at'])) {
        throw new RuntimeException('Administrator student audit did not report the recorded logins.');
    }

    echo 'Student login audit smoke test passed.' . PHP_EOL;
} finally {
    if ($studentId > 0) $db->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $studentId]);
}
