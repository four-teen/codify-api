<?php
declare(strict_types=1);

use Codify\Core\Connection;
use Codify\Repositories\TokenRepository;

require dirname(__DIR__) . '/bootstrap/autoload.php';

$db = Connection::make(); $studentId = 0;
try {
    $suffix = bin2hex(random_bytes(5));
    $passwordHash = password_hash(bin2hex(random_bytes(24)), PASSWORD_BCRYPT);
    $sql = 'INSERT INTO users (faculty_id, first_name, last_name, name, username, email, password, role, is_active, must_change_password, created_at, updated_at) VALUES (NULL, \'API\', \'Device Test\', \'API Device Test\', :username, :email, :password, \'student\', 1, 0, NOW(), NOW())';
    $statement = $db->prepare($sql);
    $statement->execute(['username' => 'api-device-' . $suffix, 'email' => 'api-device-' . $suffix . '@example.test', 'password' => $passwordHash]);
    $studentId = (int) $db->lastInsertId(); $token = (new TokenRepository($db))->issue($studentId, 15);
    $base = rtrim((string) env('CODIFY_TEST_API_URL', 'http://localhost/codify-api/api/v1'), '/');
    $request = static function (string $method, string $url, string $token, array $body = []) {
        $headers = ['Accept: application/json', 'Authorization: Bearer ' . $token];
        $options = ['http' => ['method' => $method, 'header' => implode(chr(13) . chr(10), $headers), 'ignore_errors' => true]];
        if ($method !== 'GET') { $options['http']['header'] .= chr(13) . chr(10) . 'Content-Type: application/json'; $options['http']['content'] = json_encode($body); }
        $raw = file_get_contents($url, false, stream_context_create($options));
        return is_string($raw) ? json_decode($raw, true) : null;
    };
    $overview = $request('GET', $base . '/student/device-consistency', $token);
    if (!is_array($overview) || empty($overview['success']) || !isset($overview['data']['enabled'])) throw new RuntimeException('Device overview endpoint smoke test failed.');
    if (($overview['data']['status'] ?? '') === 'consent_required') {
        $blocked = $request('GET', $base . '/student/workspace', $token);
        if (!is_array($blocked) || !empty($blocked['success']) || ($blocked['code'] ?? '') !== 'DEVICE_CONSENT_REQUIRED') throw new RuntimeException('Mandatory device-consent workspace gate smoke test failed.');
    }
    $consent = $request('POST', $base . '/student/device-consistency/consent', $token);
    if (!is_array($consent) || empty($consent['success']) || empty($consent['data']['consented'])) throw new RuntimeException('Device consent endpoint smoke test failed.');
    $signals = [
        'version' => '1', 'browser_family' => 'Compatibility Browser', 'browser_major' => '1', 'os_family' => 'Test OS',
        'device_type' => 'mobile', 'platform' => 'test', 'timezone' => 'Asia/Manila', 'languages' => ['en'],
        'screen_bucket' => '400x800', 'pixel_ratio_bucket' => '2', 'color_depth' => '24',
        'hardware_concurrency_bucket' => 'up-to-4', 'device_memory_bucket' => 'unavailable',
        'max_touch_points' => 'up-to-5', 'storage_available' => false,
    ];
    $credentialId = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $observed = $request('POST', $base . '/student/device-consistency/observe', $token, ['credential_id' => $credentialId, 'signals' => $signals]);
    $observedDevice = $observed['data']['overview']['devices'][0] ?? [];
    if (!is_array($observed) || empty($observed['success']) || ($observedDevice['verification_method'] ?? '') !== 'browser_signals') throw new RuntimeException('Compatibility browser recording endpoint smoke test failed.');
    $workspace = $request('GET', $base . '/student/workspace', $token);
    if (!is_array($workspace) || empty($workspace['success'])) throw new RuntimeException('Student workspace access after device consent smoke test failed.');
    $declined = $request('POST', $base . '/student/device-consistency/decline', $token);
    if (!is_array($declined) || empty($declined['success']) || empty($declined['data']['signed_out'])) throw new RuntimeException('Device notice decline endpoint smoke test failed.');
    $signedOut = $request('GET', $base . '/auth/me', $token);
    if (!is_array($signedOut) || !empty($signedOut['success'])) throw new RuntimeException('Declined device notice did not revoke the student session.');
    $nextToken = (new TokenRepository($db))->issue($studentId, 15);
    $nextOverview = $request('GET', $base . '/student/device-consistency', $nextToken);
    if (!is_array($nextOverview) || empty($nextOverview['success']) || ($nextOverview['data']['status'] ?? '') !== 'consent_required') throw new RuntimeException('Declined device notice was not required again on the next login. Status: ' . (string) ($nextOverview['data']['status'] ?? 'missing'));
    echo 'Device API smoke test passed.' . PHP_EOL;
} finally {
    if ($studentId > 0) $db->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $studentId]);
}
