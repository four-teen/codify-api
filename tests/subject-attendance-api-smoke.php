<?php
declare(strict_types=1);

use Codify\Core\Connection;
use Codify\Repositories\TokenRepository;

require dirname(__DIR__) . '/bootstrap/autoload.php';
$db = Connection::make(); $users = []; $offeringId = 0;
$base = rtrim((string) env('CODIFY_TEST_API_URL', 'http://localhost/codify-api/api/v1'), '/');
$call = static function (string $method, string $path, string $token = '', array $body = []) use ($base): array {
    $headers = ['Accept: application/json', 'Content-Type: application/json'];
    if ($token !== '') $headers[] = 'Authorization: Bearer ' . $token;
    $options = ['http' => ['method' => $method, 'header' => implode("\r\n", $headers), 'ignore_errors' => true, 'timeout' => 10]];
    if ($method !== 'GET') $options['http']['content'] = json_encode($body);
    $raw = file_get_contents($base . $path, false, stream_context_create($options));
    preg_match('/\s(\d{3})\s/', $http_response_header[0] ?? '', $match);
    return ['status' => (int) ($match[1] ?? 0), 'body' => json_decode((string) $raw, true)];
};
$expect = static function (array $response, int $status): array {
    if ($response['status'] !== $status) throw new RuntimeException('Attendance API expected ' . $status . ', got ' . $response['status'] . ': ' . ($response['body']['message'] ?? 'invalid response'));
    return $response['body']['data'] ?? [];
};
try {
    $suffix = bin2hex(random_bytes(6));
    foreach (['faculty', 'faculty', 'student'] as $index => $role) {
        $username = 'attendance-api-' . $index . '-' . $suffix;
        $query = $db->prepare('INSERT INTO users (name, username, email, password, role, is_active, must_change_password) VALUES (?, ?, ?, ?, ?, 1, 0)');
        $query->execute(['Attendance API fixture', $username, $username . '@example.test', password_hash(bin2hex(random_bytes(24)), PASSWORD_BCRYPT), $role]);
        $users[] = (int) $db->lastInsertId();
    }
    $subjectId = (int) $db->query('SELECT id FROM subjects ORDER BY id LIMIT 1')->fetchColumn();
    if (!$subjectId) throw new RuntimeException('An academic subject is required for the API fixture.');
    $query = $db->prepare("INSERT INTO faculty_subjects (faculty_id, subject_id, section, academic_year, academic_term, is_active) VALUES (?, ?, ?, '2026-2027', 'First Semester', 1)");
    $query->execute([$users[0], $subjectId, 'attendance-api-' . $suffix]); $offeringId = (int) $db->lastInsertId();
    $db->prepare('INSERT INTO faculty_subject_students (faculty_subject_id, student_id) VALUES (?, ?)')->execute([$offeringId, $users[2]]);
    $tokens = new TokenRepository($db); $owner = $tokens->issue($users[0], 5); $other = $tokens->issue($users[1], 5); $student = $tokens->issue($users[2], 5);
    $path = '/faculty/subject-offerings/' . $offeringId . '/attendance';
    $expect($call('GET', $path), 401);
    $expect($call('PUT', $path), 401);
    $expect($call('GET', $path, $student), 403);
    $expect($call('GET', $path, $other), 404);
    $expect($call('GET', $path . '/history', $other), 404);
    $sheet = $expect($call('GET', $path, $owner), 200);
    if ($sheet['session'] !== null) throw new RuntimeException('The API created attendance on read.');
    $past = (new DateTimeImmutable($sheet['today']))->modify('-10 days')->format('Y-m-d');
    $body = ['date' => $past, 'revision' => 0, 'notes' => 'Previous class', 'entries' => [['student_id' => $users[2], 'status' => 'present']]];
    $expect($call('PUT', $path, $other, $body), 404);
    $expect($call('PUT', $path, $student, $body), 403);
    $saved = $expect($call('PUT', $path, $owner, $body), 200);
    if ($saved['date'] !== $past || $saved['session']['revision'] !== 1) throw new RuntimeException('The API did not save the selected past date.');
    $expect($call('PUT', $path, $owner, $body), 409);
    $body['revision'] = 1; $body['entries'][0]['status'] = 'absent';
    $expect($call('PUT', $path, $owner, $body), 200);
    $history = $expect($call('GET', $path . '/history', $owner), 200);
    if (count($history['sessions']) !== 1 || $history['students'][0]['absent'] !== 1) throw new RuntimeException('The API correction did not update attendance totals.');
    $expect($call('GET', $path . '?date=2026-02-30', $owner), 422);
    echo "Attendance HTTP API smoke passed: authorization, backdating, atomic save, conflict rejection, corrections, and totals.\n";
} finally {
    if ($offeringId) $db->prepare('DELETE FROM faculty_subjects WHERE id = ?')->execute([$offeringId]);
    foreach ($users as $id) {
        (new TokenRepository($db))->revokeAll($id);
        $db->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
    }
}
