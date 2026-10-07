<?php
declare(strict_types=1);
use Codify\Core\Connection;
use Codify\Core\HttpException;
use Codify\Repositories\ProblemRubricRepository;
use Codify\Repositories\ProblemBankRepository;
use Codify\Repositories\ProblemWorkRepository;
use Codify\Repositories\StudentLearningRepository;
use Codify\Repositories\SubjectGradebookRepository;
use Codify\Repositories\SystemSettingRepository;
use Codify\Services\PythonRubricScorer;
require dirname(__DIR__) . '/bootstrap/autoload.php';
function ensure(bool $value, string $message): void { if (!$value) throw new RuntimeException($message); }
function denied(callable $call, int $status): void { try { $call(); } catch (HttpException $e) { ensure($e->status === $status, 'Unexpected rejection: ' . $e->getMessage()); return; } throw new RuntimeException('Expected rejection.'); }
$rubric = ['name' => 'Input and sum', 'description' => 'Write a Python solution.', 'criteria' => [
    ['id' => 'logic', 'title' => 'Logic', 'description' => 'Correct sum for all inputs.', 'max_points' => 50, 'check' => 'manual'],
    ['id' => 'syntax', 'title' => 'Syntax', 'max_points' => 20, 'check' => 'syntax'],
    ['id' => 'concepts', 'title' => 'Required concepts', 'max_points' => 20, 'check' => 'features', 'required_features' => ['input', 'int', 'print', 'addition']],
    ['id' => 'format', 'title' => 'Formatting', 'max_points' => 10, 'check' => 'formatting'],
]];
$rubric = ProblemRubricRepository::validate($rubric);
$scorer = new PythonRubricScorer();
$validCode = "a = int(input())\nb = int(input())\nprint(a + b)\n";
$initial = $scorer->score($validCode, $rubric);
ensure($initial['earned'] === 50.0 && $initial['checked_points'] === 50.0 && $initial['pending_points'] === 50.0, 'Wrong checked or pending points.');
ensure($initial['criteria'][0]['score'] === null, 'Manual correctness was guessed.');
$commentOnly = $scorer->score("# input() int() print() and addition\nx = 'input() int() print()'", $rubric);
ensure($commentOnly['criteria'][2]['score'] === 0.0, 'Comments or string literals earned construct points.');
$broken = $scorer->score("if True\nprint(1)", $rubric);
ensure($broken['criteria'][1]['score'] === 0 && $broken['criteria'][2]['score'] === null, 'Invalid syntax or uncheckable constructs scored incorrectly.');
$marker = sys_get_temp_dir() . '/codify-no-execution-' . bin2hex(random_bytes(6));
$malicious = "from pathlib import Path\nPath(" . json_encode($marker) . ").write_text('executed')\nraise RuntimeError('must never run')\n";
$scorer->score($malicious, $rubric);
ensure(!file_exists($marker), 'Student code was executed.');
$invalid = $rubric; $invalid['criteria'][0]['max_points'] = -5; denied(function () use ($invalid): void { ProblemRubricRepository::validate($invalid); }, 422);
$db = Connection::make(); $term = (new SystemSettingRepository($db))->current();
$query = $db->prepare('SELECT fs.id, fs.faculty_id, fss.student_id FROM faculty_subjects fs INNER JOIN faculty_subject_students fss ON fss.faculty_subject_id = fs.id WHERE fs.is_active = 1 AND fs.academic_year = ? AND fs.academic_term = ? LIMIT 1');
$query->execute([$term['academic_year'], $term['academic_term']]); $offering = $query->fetch();
if (!$offering) throw new RuntimeException('An enrolled student is required.');
$faculty = (int) $offering['faculty_id']; $student = (int) $offering['student_id'];
$rubrics = new ProblemRubricRepository($db); $problems = new ProblemBankRepository($db); $work = new ProblemWorkRepository($db);
$db->beginTransaction();
try {
    $template = $rubrics->saveTemplate($faculty, null, $rubric);
    $reflection = new ReflectionClass(\Codify\Controllers\ProblemBankController::class); $controller = $reflection->newInstanceWithoutConstructor(); $payload = $reflection->getMethod('payload'); $payload->setAccessible(true);
    $data = $payload->invoke($controller, ['code' => 'RUBRIC-' . bin2hex(random_bytes(5)), 'title' => 'Sum', 'problem_statement' => 'Read two integers and print their sum.', 'faculty_subject_ids' => [(int) $offering['id']], 'expected_output' => '']);
    $data['problem']['rubric'] = array_merge($rubric, ['template_id' => $template['id']]);
    $problem = $problems->create($faculty, $data['problem'], $data['subject_ids'], $data['test_cases'], $term['academic_year'], $term['academic_term']); $id = $problem['id'];
    ensure($problem['rubric']['max_points'] === 100.0, 'Problem rubric total failed.');
    $changed = $rubric; $changed['name'] = 'Revised template'; $changed['criteria'][0]['max_points'] = 70;
    $rubrics->saveTemplate($faculty, $template['id'], $changed);
    ensure($rubrics->problem($id)['name'] === 'Input and sum', 'Template edit changed attached copy.');
    denied(function () use ($rubrics, $template, $changed): void { $rubrics->saveTemplate(0, $template['id'], $changed); }, 404);
    $attempt = $work->act($student, $id, 'start', []);
    denied(function () use ($rubrics, $faculty, $id, $changed): void { $rubrics->attach($faculty, $id, $changed); }, 409);
    // An unchanged rubric can still be saved while editing other problem fields.
    $rubrics->attach($faculty, $id, $rubric);
    $work->act($student, $id, 'submit', ['session_token' => $attempt['session_token'], 'code' => $validCode]);
    $evaluation = $rubrics->evaluation($student, $id);
    ensure($evaluation['initial']['earned'] === 50 && $evaluation['final_score'] === null, 'Initial score was not generated at submission.');
    $gradebooks = new SubjectGradebookRepository($db);
    $gradebook = $gradebooks->gradebook($faculty, (int) $offering['id']);
    if ($gradebook['settings']['midterm_status'] === 'locked' || $gradebook['settings']['final_status'] === 'locked') {
        $weights = []; foreach ($gradebook['categories'] as $category) $weights[(int) $category['id']] = (float) $category['weight'];
        $gradebook = $gradebooks->updateSettings($faculty, (int) $offering['id'], $weights, 'draft', 'draft');
    }
    $category = null;
    foreach ($gradebook['categories'] as $candidate) if ($candidate['grading_period'] === 'midterm' && $candidate['category_key'] === 'written_activities') $category = $candidate;
    ensure($category !== null, 'The gradebook coding activity category is missing.');
    $availableCoding = null;
    foreach ($gradebook['available_activities'] as $activity) if ($activity['source_type'] === 'problem' && (int) $activity['source_id'] === $id) $availableCoding = $activity;
    ensure($availableCoding !== null && $availableCoding['results_mode'] === 'rubric' && $availableCoding['max_points'] === 100.0, 'The gradebook activity picker did not identify the rubric score source and maximum.');
    $categoryResult = static function (array $book, int $studentId, int $categoryId): array {
        foreach ($book['students'] as $row) if ((int) $row['id'] === $studentId) {
            foreach ($row['periods']['midterm']['categories'] as $result) if ((int) $result['category_id'] === $categoryId) return $result;
        }
        throw new RuntimeException('The student category calculation is missing.');
    };
    $beforePending = $categoryResult($gradebook, $student, (int) $category['id']);
    $gradebook = $gradebooks->createLinkedItem($faculty, (int) $offering['id'], [
        'source_type' => 'problem', 'source_id' => $id, 'category_id' => (int) $category['id'], 'counts_toward_grade' => true, 'due_at' => null,
    ]);
    $gradeItem = null;
    foreach ($gradebook['items'] as $item) if ($item['source_type'] === 'problem' && (int) $item['source_id'] === $id) $gradeItem = $item;
    ensure($gradeItem !== null && $gradeItem['rubric_enabled'] && $gradeItem['max_points'] === 100.0, 'The coding grade item did not use its rubric maximum.');
    $pendingStudent = null;
    foreach ($gradebook['students'] as $row) if ((int) $row['id'] === $student) $pendingStudent = $row;
    $pendingCell = $pendingStudent['scores'][$gradeItem['id']] ?? null;
    $pendingCategory = $categoryResult($gradebook, $student, (int) $category['id']);
    ensure($pendingCell['source'] === 'rubric' && $pendingCell['status'] === 'pending' && $pendingCell['score'] === null, 'The initial rubric estimate was counted before faculty finalization.');
    ensure(abs((float) $pendingCategory['earned'] - (float) $beforePending['earned']) < 0.01 && abs((float) $pendingCategory['possible'] - (float) $beforePending['possible']) < 0.01, 'A pending coding score changed category points.');
    $listed = $problems->problems($faculty, ['search' => $problem['code'], 'difficulty' => '', 'status' => '', 'offering_id' => 0]);
    ensure($listed[0]['answered_count'] === 1, 'Answered count must include submitted students only.');
    $responses = $work->responses($faculty, $id); ensure($responses[0]['evaluation']['initial']['earned'] === 50, 'Summary did not include initial score.');
    denied(function () use ($rubrics, $id, $student): void { $rubrics->generate(0, $id, $student); }, 404);
    denied(function () use ($rubrics, $faculty, $id, $student): void { $rubrics->finalize($faculty, $id, $student, ['scores' => ['logic' => 99, 'syntax' => 20, 'concepts' => 20, 'format' => 10]]); }, 422);
    denied(function () use ($rubrics, $faculty, $id, $student): void { $rubrics->finalize($faculty, $id, $student, ['scores' => ['syntax' => 20]]); }, 422);
    $final = $rubrics->finalize($faculty, $id, $student, ['scores' => ['logic' => 45, 'syntax' => 20, 'concepts' => 20, 'format' => 10], 'feedback' => 'Good solution.']);
    ensure($final['final_score'] === 95.0 && $final['initial']['earned'] === 50, 'Final score overwrote initial score.');
    $gradebook = $gradebooks->gradebook($faculty, (int) $offering['id']);
    $finalStudent = null;
    foreach ($gradebook['students'] as $row) if ((int) $row['id'] === $student) $finalStudent = $row;
    $finalCell = $finalStudent['scores'][$gradeItem['id']] ?? null;
    $finalCategory = $categoryResult($gradebook, $student, (int) $category['id']);
    ensure($finalCell['source'] === 'rubric' && $finalCell['status'] === 'graded' && $finalCell['score'] === 95.0, 'The finalized rubric score did not appear automatically in the gradebook.');
    ensure(abs(((float) $finalCategory['earned'] - (float) $beforePending['earned']) - 95.0) < 0.01 && abs(((float) $finalCategory['possible'] - (float) $beforePending['possible']) - 100.0) < 0.01, 'The finalized rubric score was not included in category calculations.');
    $storedGradeEntry = $db->prepare('SELECT COUNT(*) FROM student_grade_entries WHERE grade_item_id = ? AND student_id = ?');
    $storedGradeEntry->execute([$gradeItem['id'], $student]);
    ensure((int) $storedGradeEntry->fetchColumn() === 0, 'The rubric score was duplicated as a manual grade entry.');
    denied(function () use ($gradebooks, $faculty, $offering, $gradeItem, $student): void {
        $gradebooks->saveScores($faculty, (int) $offering['id'], [['item_id' => $gradeItem['id'], 'student_id' => $student, 'status' => 'graded', 'score' => 95.0, 'remarks' => null]]);
    }, 422);
    $db->prepare("UPDATE subject_grading_settings SET midterm_status = 'locked' WHERE faculty_subject_id = ?")->execute([(int) $offering['id']]);
    denied(function () use ($rubrics, $faculty, $id, $student): void {
        $rubrics->finalize($faculty, $id, $student, ['scores' => ['logic' => 44, 'syntax' => 20, 'concepts' => 20, 'format' => 10]]);
    }, 422);
    $db->prepare("UPDATE subject_grading_settings SET midterm_status = 'draft' WHERE faculty_subject_id = ?")->execute([(int) $offering['id']]);
    $view = (new StudentLearningRepository($db))->problem($student, $id, $term['academic_year'], $term['academic_term']);
    ensure($view['rubric']['name'] === 'Input and sum' && $view['final_score'] === 95.0 && !isset($view['initial']), 'Student rubric/final score visibility failed.');
    $rubrics->deleteTemplate($faculty, $template['id']); ensure($rubrics->problem($id)['max_points'] === 100.0, 'Template deletion removed attached rubric.');
    echo "Rubric templates, immutable copies, code checks, no execution, answer counts, initial/final scoring, gradebook sync/calculations, lock protection, and student visibility passed.\n";
} finally { $db->rollBack(); }
