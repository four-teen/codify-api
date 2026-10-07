<?php
declare(strict_types=1);

use Codify\Controllers\ProblemBankController;
use Codify\Core\Connection;
use Codify\Repositories\ProblemBankRepository;
use Codify\Repositories\StudentLearningRepository;
use Codify\Repositories\SystemSettingRepository;

require dirname(__DIR__) . '/bootstrap/autoload.php';

$db = Connection::make();
$settings = (new SystemSettingRepository($db))->current();
$query = $db->prepare('SELECT fs.id, fs.faculty_id, fss.student_id FROM faculty_subjects fs INNER JOIN faculty_subject_students fss ON fss.faculty_subject_id = fs.id WHERE fs.is_active = 1 AND fs.academic_year = ? AND fs.academic_term = ? LIMIT 1');
$query->execute([$settings['academic_year'], $settings['academic_term']]);
$offering = $query->fetch();
if (!$offering) throw new RuntimeException('A current enrolled student is required for this check.');
$reflection = new ReflectionClass(ProblemBankController::class);
$controller = $reflection->newInstanceWithoutConstructor();
$payload = $reflection->getMethod('payload');
$payload->setAccessible(true);
$faculty = new ProblemBankRepository($db);
$students = new StudentLearningRepository($db);
$input = ['code' => 'OUTPUT-' . bin2hex(random_bytes(5)), 'title' => 'Output visibility smoke', 'problem_statement' => 'Write Python.', 'faculty_subject_ids' => [(int) $offering['id']], 'is_active' => true, 'expected_output' => "  10\n20  "];
$db->beginTransaction();
try {
    $data = $payload->invoke($controller, $input);
    $problem = $faculty->create((int) $offering['faculty_id'], $data['problem'], $data['subject_ids'], $data['test_cases'], $settings['academic_year'], $settings['academic_term']);
    foreach ([false, true, false] as $visible) {
        $input['show_expected_output'] = $visible;
        $data = $payload->invoke($controller, $input);
        $saved = $faculty->update((int) $offering['faculty_id'], $problem['id'], $data['problem'], $data['subject_ids'], $data['test_cases'], $settings['academic_year'], $settings['academic_term']);
        $student = $students->problem((int) $offering['student_id'], $problem['id'], $settings['academic_year'], $settings['academic_term']);
        if ($saved['expected_output'] !== $input['expected_output'] || $saved['show_expected_output'] !== $visible) throw new RuntimeException('Faculty output did not round-trip.');
        if ($student['expected_output'] !== ($visible ? $input['expected_output'] : '') || count($student['sample_cases']) !== ($visible ? 1 : 0)) throw new RuntimeException('Student output visibility failed.');
    }
    $input['expected_output'] = '';
    $data = $payload->invoke($controller, $input);
    $saved = $faculty->update((int) $offering['faculty_id'], $problem['id'], $data['problem'], $data['subject_ids'], $data['test_cases'], $settings['academic_year'], $settings['academic_term']);
    if (!$saved['is_active'] || $saved['test_cases'] !== []) throw new RuntimeException('Active problem without output failed.');
    echo "Problem output save, whitespace, visibility, and empty output checks passed.\n";
} finally {
    $db->rollBack();
}
