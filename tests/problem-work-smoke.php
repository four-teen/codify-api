<?php
declare(strict_types=1);

use Codify\Core\Connection;
use Codify\Core\HttpException;
use Codify\Repositories\ProblemBankRepository;
use Codify\Repositories\ProblemWorkRepository;
use Codify\Repositories\SystemSettingRepository;
use Codify\Controllers\ProblemBankController;

require dirname(__DIR__) . '/bootstrap/autoload.php';
function check(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function rejected(callable $call): void {
    try { $call(); } catch (HttpException $error) { check($error->status === 409, 'Expected inactive session conflict.'); return; }
    throw new RuntimeException('Inactive session was accepted.');
}
$db = Connection::make();
$term = (new SystemSettingRepository($db))->current();
$query = $db->prepare('SELECT fs.id, fs.faculty_id, fss.student_id FROM faculty_subjects fs INNER JOIN faculty_subject_students fss ON fss.faculty_subject_id = fs.id WHERE fs.is_active = 1 AND fs.academic_year = ? AND fs.academic_term = ? LIMIT 1');
$query->execute([$term['academic_year'], $term['academic_term']]);
$offering = $query->fetch();
if (!$offering) throw new RuntimeException('A current enrolled student is required.');
$student = (int) $offering['student_id'];
$facultyId = (int) $offering['faculty_id'];
$reflection = new ReflectionClass(ProblemBankController::class);
$controller = $reflection->newInstanceWithoutConstructor();
$method = $reflection->getMethod('payload'); $method->setAccessible(true);
$problems = new ProblemBankRepository($db);
$work = new ProblemWorkRepository($db);
$db->beginTransaction();
try {
    $ids = [];
    for ($number = 0; $number < 3; $number++) {
        $data = $method->invoke($controller, ['code' => 'WORK-' . bin2hex(random_bytes(5)), 'title' => 'Written workflow test', 'problem_statement' => 'Write Python.', 'faculty_subject_ids' => [(int) $offering['id']], 'expected_output' => '']);
        $problem = $problems->create($facultyId, $data['problem'], $data['subject_ids'], $data['test_cases'], $term['academic_year'], $term['academic_term']);
        $ids[] = $problem['id'];
    }
    $problem = $ids[0];
    check($work->state($student, $problem)['status'] === 'ready', 'Initial state failed.');
    $attempt = $work->act($student, $problem, 'start', []);
    check(strlen($attempt['session_token']) === 64, 'Session token missing.');
    check(!isset($work->state($student, $problem)['session_token']), 'State leaked session token.');
    $old = $attempt['session_token'];
    $closed = $work->act($student, $problem, 'close', ['session_token' => $old, 'reason' => 'clipboard']);
    check($closed['close_count'] === 1 && $closed['status'] === 'closed', 'First close failed.');
    $duplicate = $work->act($student, $problem, 'close', ['session_token' => $old, 'reason' => 'pagehide']);
    check($duplicate['close_count'] === 1, 'Duplicate close counted twice.');
    rejected(function () use ($work, $student, $problem, $old): void { $work->act($student, $problem, 'submit', ['session_token' => $old, 'code' => 'print(1)']); });
    $second = $work->act($student, $problem, 'start', []);
    $stale = $work->act($student, $problem, 'close', ['session_token' => $old]);
    check($stale['status'] === 'active' && $stale['close_count'] === 1, 'Old tab closed newer session.');
    $third = $work->act($student, $problem, 'start', []);
    check($third['close_count'] === 2 && $third['session_token'] !== $second['session_token'], 'Reopen did not invalidate previous attempt.');
    rejected(function () use ($work, $student, $problem, $second): void { $work->act($student, $problem, 'heartbeat', ['session_token' => $second['session_token']]); });
    $locked = $work->act($student, $problem, 'close', ['session_token' => $third['session_token'], 'reason' => 'hidden']);
    check($locked['close_count'] === 3 && $locked['status'] === 'locked', 'Third close did not lock.');
    check($work->act($student, $problem, 'start', [])['status'] === 'locked', 'Lock bypassed by restart.');
    $reloadedRepository = new ProblemWorkRepository($db);
    check($reloadedRepository->state($student, $problem)['close_count'] === 3, 'Server counter was not persistent.');

    $problem = $ids[1];
    $attempt = $work->act($student, $problem, 'start', []);
    $answer = "a = int(input())\nprint(a + 1)\n";
    $submitted = $work->act($student, $problem, 'submit', ['session_token' => $attempt['session_token'], 'code' => $answer]);
    check($submitted['status'] === 'submitted' && $submitted['submitted_code'] === $answer, 'Submission not saved exactly.');
    check($work->act($student, $problem, 'close', ['session_token' => $attempt['session_token']])['close_count'] === 0, 'Closing after submission consumed a close.');
    check($work->act($student, $problem, 'start', [])['status'] === 'submitted', 'Submitted answer was editable.');
    check($work->responses($facultyId, $problem)[0]['submitted_code'] === $answer, 'Faculty could not review code.');
    try { $work->responses(0, $problem); throw new RuntimeException('Another faculty could access answers.'); } catch (HttpException $error) { check($error->status === 404, 'Faculty ownership failed.'); }

    $problem = $ids[2];
    $attempt = $work->act($student, $problem, 'start', []);
    $db->prepare('UPDATE coding_problem_work SET last_seen_at = DATE_SUB(NOW(), INTERVAL 100 SECOND) WHERE student_id = ? AND problem_id = ?')->execute([$student, $problem]);
    $expired = $work->act($student, $problem, 'submit', ['session_token' => $attempt['session_token'], 'code' => 'print(1)']);
    check($expired['status'] === 'closed' && $expired['close_count'] === 1 && $expired['submitted_code'] === '', 'Disconnected attempt submitted code.');
    echo "Written Python sessions: duplicate/stale events, reopen, third-close lock, submission, faculty scope, and expiry passed.\n";
} finally { $db->rollBack(); }
