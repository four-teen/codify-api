<?php
declare(strict_types=1);

use Codify\Core\Connection;
use Codify\Core\HttpException;
use Codify\Core\Request;
use Codify\Repositories\SubjectAttendanceRepository;
use Codify\Repositories\TokenRepository;
use Codify\Repositories\UserRepository;
use Codify\Repositories\SystemSettingRepository;
use Codify\Repositories\DeviceConsistencyRepository;
use Codify\Services\AuthGuard;

require dirname(__DIR__) . '/bootstrap/autoload.php';

function checkAttendance(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function attendanceRejects(int $status, callable $callback): void {
    try { $callback(); } catch (HttpException $exception) {
        checkAttendance($exception->status === $status, 'Unexpected rejection: ' . $exception->getMessage()); return;
    }
    throw new RuntimeException('Expected attendance rejection ' . $status);
}

$db = Connection::make();
$repository = new SubjectAttendanceRepository($db);
$subjectId = (int) $db->query('SELECT id FROM subjects ORDER BY id LIMIT 1')->fetchColumn();
checkAttendance($subjectId > 0, 'An academic subject is required for attendance fixtures.');
$protectedTables = ['student_assessment_attempts', 'subject_grade_items', 'student_grade_entries'];
$before = [];
foreach ($protectedTables as $table) $before[$table] = $db->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
$db->beginTransaction();
try {
    $suffix = bin2hex(random_bytes(6));
    $makeUser = static function (string $role, string $label) use ($db, $suffix): int {
        $query = $db->prepare('INSERT INTO users (name, username, email, password, role, is_active, must_change_password) VALUES (?, ?, ?, ?, ?, 1, 0)');
        $query->execute([$label, $label . $suffix, $label . $suffix . '@example.test', password_hash(bin2hex(random_bytes(24)), PASSWORD_BCRYPT), $role]);
        return (int) $db->lastInsertId();
    };
    $faculty = $makeUser('faculty', 'AttendanceFaculty'); $otherFaculty = $makeUser('faculty', 'OtherFaculty');
    $studentA = $makeUser('student', 'Student A'); $studentB = $makeUser('student', 'Student B'); $studentC = $makeUser('student', 'Student C');
    $query = $db->prepare("INSERT INTO faculty_subjects (faculty_id, subject_id, section, academic_year, academic_term, is_active) VALUES (?, ?, ?, '2026-2027', 'First Semester', 1)");
    $query->execute([$faculty, $subjectId, 'attendance-test-' . $suffix]); $offeringId = (int) $db->lastInsertId();
    $enroll = $db->prepare('INSERT INTO faculty_subject_students (faculty_subject_id, student_id) VALUES (?, ?)');
    $enroll->execute([$offeringId, $studentA]); $enroll->execute([$offeringId, $studentB]);
    $today = $repository->today(); $past = (new DateTimeImmutable($today))->modify('-7 days')->format('Y-m-d');
    $future = (new DateTimeImmutable($today))->modify('+1 day')->format('Y-m-d');
    $blank = $repository->sheet($faculty, $offeringId, $past);
    checkAttendance($blank['session'] === null && count($blank['students']) === 2, 'A new date must use the current roster.');
    checkAttendance(count($repository->history($faculty, $offeringId)['sessions']) === 0, 'Reading a sheet must not create absences.');
    attendanceRejects(404, static function () use ($repository, $otherFaculty, $offeringId) { $repository->sheet($otherFaculty, $offeringId); });
    attendanceRejects(404, static function () use ($repository, $otherFaculty, $offeringId) { $repository->history($otherFaculty, $offeringId); });
    $input = ['date' => $past, 'revision' => 0, 'notes' => 'Backdated class', 'entries' => [
        ['student_id' => $studentA, 'status' => 'present'], ['student_id' => $studentB, 'status' => 'absent'],
    ]];
    attendanceRejects(404, static function () use ($repository, $otherFaculty, $offeringId, $input) { $repository->save($otherFaculty, $offeringId, $input); });
    foreach ([$future, '2026-02-30', '2026-1-01', 'invalid'] as $date) {
        attendanceRejects(422, static function () use ($repository, $faculty, $offeringId, $input, $date) { $input['date'] = $date; $repository->save($faculty, $offeringId, $input); });
    }
    attendanceRejects(422, static function () use ($repository, $faculty, $offeringId, $input) { $input['entries'][] = $input['entries'][0]; $repository->save($faculty, $offeringId, $input); });
    attendanceRejects(422, static function () use ($repository, $faculty, $offeringId, $input) { $input['entries'][0]['status'] = 'late'; $repository->save($faculty, $offeringId, $input); });
    attendanceRejects(422, static function () use ($repository, $faculty, $offeringId, $input) { $input['notes'] = str_repeat('x', 501); $repository->save($faculty, $offeringId, $input); });
    attendanceRejects(409, static function () use ($repository, $faculty, $offeringId, $input, $studentC) { $input['entries'][0]['student_id'] = $studentC; $repository->save($faculty, $offeringId, $input); });
    attendanceRejects(409, static function () use ($repository, $faculty, $offeringId, $input) { array_pop($input['entries']); $repository->save($faculty, $offeringId, $input); });
    checkAttendance(count($repository->history($faculty, $offeringId)['sessions']) === 0, 'Invalid saves left partial sessions.');
    $saved = $repository->save($faculty, $offeringId, $input);
    checkAttendance($saved['session']['revision'] === 1 && $saved['date'] === $past, 'Backdating failed.');
    attendanceRejects(409, static function () use ($repository, $faculty, $offeringId, $input) { $repository->save($faculty, $offeringId, $input); });
    $input['revision'] = 1; $input['entries'][1]['status'] = 'present';
    $updated = $repository->save($faculty, $offeringId, $input);
    checkAttendance($updated['session']['id'] === $saved['session']['id'] && $updated['session']['revision'] === 2, 'Editing created a duplicate sheet.');
    // Enrollment changes must not rewrite the past or count a newly added student as absent.
    $db->prepare('DELETE FROM faculty_subject_students WHERE faculty_subject_id = ? AND student_id = ?')->execute([$offeringId, $studentB]);
    $enroll->execute([$offeringId, $studentC]);
    $historical = $repository->sheet($faculty, $offeringId, $past);
    checkAttendance(array_column($historical['students'], 'student_id') === [$studentA, $studentB], 'Saved roster changed after enrollment edits.');
    $input['revision'] = 2; $input['entries'][1]['status'] = 'absent';
    $repository->save($faculty, $offeringId, $input);
    $input['date'] = $today; $input['revision'] = 0; $input['entries'][1]['student_id'] = $studentC;
    $repository->save($faculty, $offeringId, $input);
    $history = $repository->history($faculty, $offeringId);
    $totals = array_column($history['students'], null, 'student_id');
    checkAttendance(count($history['sessions']) === 2, 'Expected two unique dates.');
    checkAttendance($totals[$studentA]['present'] === 2 && $totals[$studentA]['percentage'] === 100.0, 'Present totals failed.');
    checkAttendance($totals[$studentC]['recorded'] === 1 && !isset($totals[$studentC]['dates'][$past]), 'New enrollment gained historical absences.');
    checkAttendance($totals[$studentB]['recorded'] === 1 && $totals[$studentB]['absent'] === 1, 'Removed enrollment lost historical attendance.');
    foreach ($protectedTables as $table) checkAttendance($before[$table] === $db->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn(), 'Attendance modified ' . $table);

    $tokens = new TokenRepository($db);
    $guard = new AuthGuard($tokens, new UserRepository($db), new SystemSettingRepository($db), new DeviceConsistencyRepository($db));
    $_SERVER['HTTP_AUTHORIZATION'] = ''; $request = Request::capture();
    attendanceRejects(401, static function () use ($guard, $request) { $guard->authenticate($request, true, 'faculty'); });
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $tokens->issue($studentA, 5);
    attendanceRejects(403, static function () use ($guard, $request) { $guard->authenticate($request, true, 'faculty'); });
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $tokens->issue($faculty, 5);
    checkAttendance($guard->authenticate($request, true, 'faculty')['id'] === $faculty, 'Faculty authorization failed.');
    $db->rollBack();
} catch (Throwable $exception) {
    if ($db->inTransaction()) $db->rollBack(); throw $exception;
}
echo "Attendance smoke test passed: backdating, corrections, ownership, validation, conflicts, history, totals, and gradebook isolation. All fixtures rolled back.\n";
