<?php
declare(strict_types=1);

use Codify\Core\Connection;
use Codify\Repositories\StudentLearningRepository;
use Codify\Repositories\SystemSettingRepository;
use Codify\Repositories\TokenRepository;

require dirname(__DIR__) . '/bootstrap/autoload.php';
$db = Connection::make(); $users = []; $offerings = [];
$base = rtrim((string) env('CODIFY_TEST_API_URL', 'http://localhost/codify-api/api/v1'), '/');
function previewCheck(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
$call = static function (string $method, string $path, string $token = '') use ($base): array {
    $headers = ['Accept: application/json', 'Content-Type: application/json'];
    if ($token !== '') $headers[] = 'Authorization: Bearer ' . $token;
    $options = ['http' => ['method' => $method, 'header' => implode("\r\n", $headers), 'ignore_errors' => true, 'timeout' => 10]];
    if ($method !== 'GET') $options['http']['content'] = '{}';
    $raw = file_get_contents($base . $path, false, stream_context_create($options));
    preg_match('/\s(\d{3})\s/', $http_response_header[0] ?? '', $match);
    return ['status' => (int) ($match[1] ?? 0), 'body' => json_decode((string) $raw, true)];
};
$expect = static function (array $response, int $status): array {
    previewCheck($response['status'] === $status, 'Expected HTTP ' . $status . ', got ' . $response['status'] . ': ' . ($response['body']['message'] ?? 'Invalid response'));
    return $response['body']['data'] ?? [];
};
try {
    $suffix = bin2hex(random_bytes(6));
    foreach (['faculty', 'faculty', 'student', 'student'] as $index => $role) {
        $username = 'student-preview-' . $index . '-' . $suffix;
        $db->prepare('INSERT INTO users (name, username, email, password, role, is_active, must_change_password) VALUES (?, ?, ?, ?, ?, 1, 0)')
            ->execute(['Student preview fixture ' . $index, $username, $username . '@example.test', password_hash(bin2hex(random_bytes(24)), PASSWORD_BCRYPT), $role]);
        $users[] = (int) $db->lastInsertId();
    }
    [$faculty, $otherFaculty, $student, $outsider] = $users;
    $term = (new SystemSettingRepository($db))->current();
    $subject = (int) $db->query('SELECT id FROM subjects ORDER BY id LIMIT 1')->fetchColumn();
    previewCheck($subject > 0, 'An academic subject is required for test fixtures.');
    foreach ([$faculty, $otherFaculty] as $index => $owner) {
        $db->prepare('INSERT INTO faculty_subjects (faculty_id, subject_id, section, academic_year, academic_term, is_active) VALUES (?, ?, ?, ?, ?, 1)')
            ->execute([$owner, $subject, 'preview-' . $index . '-' . $suffix, $term['academic_year'], $term['academic_term']]);
        $offerings[] = (int) $db->lastInsertId();
        $db->prepare('INSERT INTO faculty_subject_students (faculty_subject_id, student_id) VALUES (?, ?)')->execute([$offerings[$index], $student]);
    }
    [$offering, $otherOffering] = $offerings;
    $makeProblem = static function (int $owner, int $subject, string $code, int $active = 1) use ($db, $suffix): int {
        $db->prepare('INSERT INTO coding_problems (faculty_id, code, title, problem_statement, starter_code, reference_solution, is_active) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$owner, $code . $suffix, $code, 'Print a greeting.', 'print("hello")', 'FACULTY ONLY SOLUTION', $active]);
        $id = (int) $db->lastInsertId();
        $db->prepare('INSERT INTO coding_problem_subjects (problem_id, faculty_subject_id) VALUES (?, ?)')->execute([$id, $subject]);
        foreach ([1, 0] as $position => $sample) $db->prepare('INSERT INTO coding_problem_test_cases (problem_id, position, input_data, expected_output, is_sample) VALUES (?, ?, ?, ?, ?)')->execute([$id, $position + 1, $sample ? 'public' : 'SECRET', 'hello', $sample]);
        return $id;
    };
    $problem = $makeProblem($faculty, $offering, 'Visible');
    $hidden = $makeProblem($faculty, $offering, 'Unpublished', 0);
    $foreignProblem = $makeProblem($otherFaculty, $otherOffering, 'OtherClass');
    $db->prepare('INSERT INTO coding_problem_subjects (problem_id, faculty_subject_id) VALUES (?, ?)')->execute([$problem, $otherOffering]);
    $makeAssessment = static function (int $owner, int $subject, string $code, int $active = 1) use ($db, $suffix): int {
        $db->prepare('INSERT INTO assessment_banks (faculty_id, code, title, bank_type, is_active) VALUES (?, ?, ?, ?, ?)')->execute([$owner, $code . $suffix, $code, 'quiz', $active]);
        $id = (int) $db->lastInsertId();
        $db->prepare('INSERT INTO assessment_bank_subjects (assessment_bank_id, faculty_subject_id) VALUES (?, ?)')->execute([$id, $subject]);
        $db->prepare('INSERT INTO assessment_bank_questions (assessment_bank_id, position, question_type, question_text, options_json, correct_answers_json, points) VALUES (?, 1, ?, ?, ?, ?, 5)')->execute([$id, 'multiple_choice', 'Choose one', '["A","B"]', '[0]']);
        return $id;
    };
    $quiz = $makeAssessment($faculty, $offering, 'AvailableQuiz');
    $completed = $makeAssessment($faculty, $offering, 'CompletedQuiz');
    $hiddenQuiz = $makeAssessment($faculty, $offering, 'UnpublishedQuiz', 0);
    $foreignQuiz = $makeAssessment($otherFaculty, $otherOffering, 'OtherQuiz');
    $db->prepare('INSERT INTO student_assessment_attempts (assessment_bank_id, faculty_subject_id, student_id, answers_json, auto_score, total_points) VALUES (?, ?, ?, ?, 5, 5)')->execute([$completed, $offering, $student, '{}']);
    $tokens = new TokenRepository($db);
    $ownerToken = $tokens->issue($faculty, 5); $otherToken = $tokens->issue($otherFaculty, 5); $studentToken = $tokens->issue($student, 5);
    $path = '/faculty/subject-offerings/' . $offering . '/students/' . $student . '/view';
    foreach (['', '/problems/' . $problem, '/assessments/' . $quiz, '/syllabus'] as $resource) {
        $expect($call('GET', $path . $resource), 401);
        $expect($call('GET', $path . $resource, $studentToken), 403);
        $expect($call('GET', $path . $resource, $otherToken), 404);
        $expect($call('GET', str_replace('/students/' . $student, '/students/' . $outsider, $path) . $resource, $ownerToken), 404);
    }
    $snapshot = static function () use ($db, $student): array {
        $result = [];
        foreach (['student_assessment_attempts', 'student_assessment_retake_permissions', 'student_device_events'] as $table) {
            $query = $db->prepare('SELECT * FROM ' . $table . ' WHERE student_id = ?'); $query->execute([$student]); $result[$table] = $query->fetchAll();
        }
        $query = $db->prepare('SELECT * FROM users WHERE id = ?'); $query->execute([$student]); $result['account'] = $query->fetch();
        $query = $db->prepare('SELECT * FROM personal_access_tokens WHERE tokenable_id = ?'); $query->execute([$student]); $result['tokens'] = $query->fetchAll();
        return $result;
    };
    $before = $snapshot();
    $payload = $expect($call('GET', $path, $ownerToken), 200);
    $learning = new StudentLearningRepository($db);
    $expected = $learning->subjectDashboard($student, $offering, $term['academic_year'], $term['academic_term']);
    previewCheck($payload['dashboard'] == $expected, 'Preview differs from the actual student dashboard.');
    previewCheck(array_column($payload['dashboard']['problems'], 'id') === [$problem], 'Preview leaked a hidden or unrelated problem.');
    $detail = $expect($call('GET', $path . '/problems/' . $problem, $ownerToken), 200);
    previewCheck(array_column($detail['subjects'], 'id') === [$offering], 'Shared problem leaked another subject.');
    previewCheck(count($detail['sample_cases']) === 1 && !isset($detail['reference_solution']), 'Hidden tests or solutions were exposed.');
    previewCheck(count($learning->problem($student, $problem, $term['academic_year'], $term['academic_term'])['subjects']) === 2, 'Normal student problem scope changed.');
    $questions = $expect($call('GET', $path . '/assessments/' . $quiz, $ownerToken), 200);
    previewCheck(count($questions['questions']) === 1 && !isset($questions['questions'][0]['correct_answers_json']), 'Question preview exposed the answer key.');
    foreach ([$hidden, $foreignProblem] as $id) $expect($call('GET', $path . '/problems/' . $id, $ownerToken), 404);
    foreach ([$hiddenQuiz, $foreignQuiz] as $id) $expect($call('GET', $path . '/assessments/' . $id, $ownerToken), 404);
    $expect($call('GET', $path . '/assessments/' . $completed, $ownerToken), 409);
    $expect($call('GET', $path . '/syllabus', $ownerToken), 404);
    foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) $expect($call($method, $path, $ownerToken), 404);
    $expect($call('POST', $path . '/assessments/' . $quiz . '/submit', $ownerToken), 404);
    $expect($call('POST', $path . '/problems/' . $problem . '/run', $ownerToken), 404);
    $expect($call('POST', '/student/subjects/' . $offering . '/assessments/' . $quiz . '/submit', $ownerToken), 403);
    $expect($call('POST', '/student/problems/' . $problem . '/run', $ownerToken), 403);
    previewCheck($snapshot() === $before, 'Preview changed student records, events, or sessions.');
    // Availability and membership are checked again, including direct content URLs.
    $db->prepare('UPDATE faculty_subjects SET is_active = 0 WHERE id = ?')->execute([$offering]);
    foreach (['', '/problems/' . $problem, '/assessments/' . $quiz, '/syllabus'] as $resource) $expect($call('GET', $path . $resource, $ownerToken), 404);
    $db->prepare('UPDATE faculty_subjects SET is_active = 1, academic_year = ? WHERE id = ?')->execute(['1900-1901', $offering]);
    $expect($call('GET', $path, $ownerToken), 404);
    $db->prepare('UPDATE faculty_subjects SET academic_year = ? WHERE id = ?')->execute([$term['academic_year'], $offering]);
    $db->prepare('UPDATE users SET is_active = 0 WHERE id = ?')->execute([$student]);
    $expect($call('GET', $path, $ownerToken), 403);
    $db->prepare('UPDATE users SET is_active = 1 WHERE id = ?')->execute([$student]);
    $db->prepare('DELETE FROM faculty_subject_students WHERE faculty_subject_id = ? AND student_id = ?')->execute([$offering, $student]);
    foreach (['', '/problems/' . $problem, '/assessments/' . $quiz, '/syllabus'] as $resource) $expect($call('GET', $path . $resource, $ownerToken), 404);
    echo "Student preview API smoke passed: faculty authorization, enrollment, exact student visibility, subject isolation, unpublished content, completed attempts, read-only requests, and unchanged student records.\n";
} finally {
    foreach ($offerings as $id) $db->prepare('DELETE FROM faculty_subjects WHERE id = ?')->execute([$id]);
    foreach ($users as $id) { (new TokenRepository($db))->revokeAll($id); $db->prepare('DELETE FROM users WHERE id = ?')->execute([$id]); }
}
