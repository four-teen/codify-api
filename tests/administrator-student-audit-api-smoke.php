<?php
declare(strict_types=1);

use Codify\Core\Connection;
use Codify\Repositories\DeviceConsistencyRepository;
use Codify\Repositories\TokenRepository;
use Codify\Services\DeviceFingerprintService;

require dirname(__DIR__) . '/bootstrap/autoload.php';

$db = Connection::make();
$administratorId = 0;
$studentId = 0;

try {
    $suffix = bin2hex(random_bytes(5));
    $insert = $db->prepare('INSERT INTO users (faculty_id, first_name, last_name, name, username, email, password, role, is_active, must_change_password, created_at, updated_at) VALUES (NULL, :first_name, :last_name, :name, :username, :email, :password, :role, 1, 0, NOW(), NOW())');
    $insert->execute([
        'first_name' => 'Audit',
        'last_name' => 'Administrator',
        'name' => 'Audit Administrator',
        'username' => 'audit-admin-' . $suffix,
        'email' => 'audit-admin-' . $suffix . '@example.test',
        'password' => password_hash('temporary-test-password-123', PASSWORD_BCRYPT),
        'role' => 'administrator',
    ]);
    $administratorId = (int) $db->lastInsertId();
    $insert->execute([
        'first_name' => 'Student',
        'last_name' => 'AuditSearch' . $suffix,
        'name' => 'Student AuditSearch' . $suffix,
        'username' => 'audit-student-' . $suffix,
        'email' => 'audit-student-' . $suffix . '@example.test',
        'password' => password_hash('temporary-test-password-123', PASSWORD_BCRYPT),
        'role' => 'student',
    ]);
    $studentId = (int) $db->lastInsertId();

    $tokens = new TokenRepository($db);
    $administratorToken = $tokens->issue($administratorId, 15);
    $studentToken = $tokens->issue($studentId, 15);
    [$studentTokenId] = explode('|', $studentToken, 2);

    $fingerprints = new DeviceFingerprintService(str_repeat('administrator-audit-test-key-', 2));
    $signals = [
        'version' => '1', 'browser_family' => 'Audit Browser', 'browser_major' => '1', 'os_family' => 'Test OS',
        'device_type' => 'desktop', 'platform' => 'test', 'timezone' => 'Asia/Manila', 'languages' => ['en'],
        'screen_bucket' => '1200x800', 'pixel_ratio_bucket' => '1', 'color_depth' => '24',
        'hardware_concurrency_bucket' => 'up-to-4', 'device_memory_bucket' => 'up-to-4',
        'max_touch_points' => '0', 'storage_available' => true,
    ];
    $deviceRepository = new DeviceConsistencyRepository($db);
    $fingerprint = $fingerprints->build($studentId, $signals);
    $device = $deviceRepository->createDevice($studentId, str_repeat('a', 43), json_encode(['kty' => 'EC']), $fingerprint, 'recognized');
    $deviceRepository->recordSession($studentId, (int) $device['id'], (int) $studentTokenId, 'first_seen', [], null, $fingerprint);

    $base = rtrim((string) env('CODIFY_TEST_API_URL', 'http://localhost/codify-api/api/v1'), '/');
    $request = static function (string $method, string $url, string $token) {
        $headers = ['Accept: application/json', 'Authorization: Bearer ' . $token];
        $options = ['http' => ['method' => $method, 'header' => implode(chr(13) . chr(10), $headers), 'ignore_errors' => true]];
        if ($method !== 'GET' && $method !== 'DELETE') {
            $options['http']['header'] .= chr(13) . chr(10) . 'Content-Type: application/json';
            $options['http']['content'] = '{}';
        }
        $raw = file_get_contents($url, false, stream_context_create($options));
        return is_string($raw) ? json_decode($raw, true) : null;
    };

    $list = $request('GET', $base . '/admin/student-audit?search=' . urlencode('AuditSearch' . $suffix), $administratorToken);
    if (!is_array($list) || empty($list['success']) || (int) ($list['data']['total'] ?? 0) !== 1 || (int) ($list['data']['data'][0]['id'] ?? 0) !== $studentId) throw new RuntimeException('Student audit search endpoint smoke test failed.');

    $emailList = $request('GET', $base . '/admin/student-audit?search=' . urlencode('audit-student-' . $suffix . '@example.test'), $administratorToken);
    $idList = $request('GET', $base . '/admin/student-audit?search=' . $studentId, $administratorToken);
    if ((int) ($emailList['data']['data'][0]['id'] ?? 0) !== $studentId || (int) ($idList['data']['data'][0]['id'] ?? 0) !== $studentId) throw new RuntimeException('Student audit email or ID search smoke test failed.');

    $forbidden = $request('GET', $base . '/admin/student-audit', $studentToken);
    if (!is_array($forbidden) || !empty($forbidden['success'])) throw new RuntimeException('Student audit authorization smoke test failed.');

    $detail = $request('GET', $base . '/admin/student-audit/' . $studentId, $administratorToken);
    if (!is_array($detail) || empty($detail['success']) || (int) ($detail['data']['fingerprinting']['summary']['total'] ?? 0) !== 1) throw new RuntimeException('Student audit dashboard endpoint smoke test failed.');

    $cleared = $request('DELETE', $base . '/admin/student-audit/' . $studentId . '/login-events', $administratorToken);
    if (!is_array($cleared) || empty($cleared['success']) || count($cleared['data']['fingerprinting']['events'] ?? []) !== 0 || count($cleared['data']['administrator_audit'] ?? []) !== 1 || (int) ($cleared['data']['active_sessions'] ?? 0) !== 1) throw new RuntimeException('Clear recorded logins endpoint smoke test failed.');

    $removed = $request('DELETE', $base . '/admin/student-audit/' . $studentId . '/devices/' . (int) $device['id'], $administratorToken);
    if (!is_array($removed) || empty($removed['success']) || (int) ($removed['data']['fingerprinting']['summary']['total'] ?? -1) !== 0 || (int) ($removed['data']['active_sessions'] ?? -1) !== 0) throw new RuntimeException('Remove device endpoint smoke test failed.');

    $secondStudentToken = $tokens->issue($studentId, 15);
    [$secondStudentTokenId] = explode('|', $secondStudentToken, 2);
    $secondDevice = $deviceRepository->createDevice($studentId, str_repeat('b', 43), json_encode(['kty' => 'EC']), $fingerprint, 'recognized');
    $deviceRepository->recordSession($studentId, (int) $secondDevice['id'], (int) $secondStudentTokenId, 'first_seen', [], null, $fingerprint);
    $revoked = $request('POST', $base . '/admin/student-audit/' . $studentId . '/revoke-sessions', $administratorToken);
    if (!is_array($revoked) || empty($revoked['success']) || (int) ($revoked['data']['active_sessions'] ?? -1) !== 0 || (int) ($revoked['data']['fingerprinting']['summary']['total'] ?? 0) !== 1) throw new RuntimeException('Revoke sessions endpoint smoke test failed.');

    $resetStudentToken = $tokens->issue($studentId, 15);
    [$resetStudentTokenId] = explode('|', $resetStudentToken, 2);
    $deviceRepository->recordSession($studentId, (int) $secondDevice['id'], (int) $resetStudentTokenId, 'recognized', [], null, $fingerprint);

    $reset = $request('DELETE', $base . '/admin/student-audit/' . $studentId . '/devices', $administratorToken);
    if (!is_array($reset) || empty($reset['success']) || (int) ($reset['data']['fingerprinting']['summary']['total'] ?? -1) !== 0 || (int) ($reset['data']['active_sessions'] ?? -1) !== 0) throw new RuntimeException('Reset fingerprints endpoint smoke test failed.');

    echo 'Administrator student audit API smoke test passed.' . PHP_EOL;
} finally {
    if ($studentId > 0 || $administratorId > 0) {
        $statement = $db->prepare('DELETE FROM administrator_student_audit_logs WHERE subject_user_id = :student OR actor_user_id = :administrator');
        $statement->execute(['student' => $studentId ?: -1, 'administrator' => $administratorId ?: -1]);
    }
    if ($studentId > 0) $db->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $studentId]);
    if ($administratorId > 0) $db->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $administratorId]);
}
