<?php
declare(strict_types=1);

use Codify\Core\Connection;
use Codify\Repositories\DeviceConsistencyRepository;
use Codify\Services\DeviceFingerprintService;

require dirname(__DIR__) . '/bootstrap/autoload.php';

$db = Connection::make(); $db->beginTransaction();
try {
    $suffix = bin2hex(random_bytes(5));
    $passwordHash = password_hash(bin2hex(random_bytes(24)), PASSWORD_BCRYPT);
    $sql = 'INSERT INTO users (faculty_id, first_name, last_name, name, username, email, password, role, is_active, must_change_password, created_at, updated_at) VALUES (NULL, \'Device\', \'Test\', \'Device Test\', :username, :email, :password, \'student\', 1, 0, NOW(), NOW())';
    $statement = $db->prepare($sql);
    $statement->execute(['username' => 'device-test-' . $suffix, 'email' => 'device-test-' . $suffix . '@example.test', 'password' => $passwordHash]);
    $studentId = (int) $db->lastInsertId();
    $sql = 'INSERT INTO personal_access_tokens (tokenable_type, tokenable_id, name, token, abilities, expires_at, created_at, updated_at) VALUES (\'Codify\\\\User\', :student, \'device-test\', :token, :abilities, DATE_ADD(NOW(), INTERVAL 1 HOUR), NOW(), NOW())';
    $statement = $db->prepare($sql);
    $statement->execute(['student' => $studentId, 'token' => hash('sha256', random_bytes(32)), 'abilities' => json_encode(['*'])]);
    $tokenId = (int) $db->lastInsertId();
    $repository = new DeviceConsistencyRepository($db);
    $service = new DeviceFingerprintService(str_repeat('repository-test-key-', 3));
    $signals = [
        'version' => '1', 'browser_family' => 'Test Browser', 'browser_major' => '1', 'os_family' => 'Test OS',
        'device_type' => 'desktop', 'platform' => 'test', 'timezone' => 'Asia/Manila', 'languages' => ['en'],
        'screen_bucket' => '1200x800', 'pixel_ratio_bucket' => '1', 'color_depth' => '24',
        'hardware_concurrency_bucket' => 'up-to-4', 'device_memory_bucket' => 'up-to-4',
        'max_touch_points' => '0', 'storage_available' => true,
    ];
    $fingerprint = $service->build($studentId, $signals);
    $repository->recordConsent($studentId, 'test', 'granted', hash('sha256', 'notice'), null);
    $device = $repository->createObservedDevice($studentId, str_repeat('c', 43), $fingerprint, 'recognized');
    if (!$repository->usesBrowserSignals($device)) throw new RuntimeException('Compatibility device mode was not stored.');
    $repository->promoteToBrowserKey($studentId, (int) $device['id'], json_encode(['kty' => 'EC']));
    $device = $repository->findOwnedDevice($studentId, (int) $device['id']);
    if ($repository->usesBrowserSignals($device)) throw new RuntimeException('Compatibility device was not promoted to a browser key.');
    $repository->recordSession($studentId, (int) $device['id'], $tokenId, 'first_seen', [], null, $fingerprint);
    $challenge = $repository->createChallenge($studentId, (int) $device['id'], $tokenId);
    $repository->consumeChallenge($challenge['id'], $studentId, (int) $device['id'], $tokenId);
    if (count($repository->devices($studentId)) !== 1 || count($repository->recentEvents($studentId)) !== 1) throw new RuntimeException('Device repository smoke test failed.');
    $db->rollBack(); echo 'Device repository smoke test passed.' . PHP_EOL;
} catch (Throwable $exception) {
    if ($db->inTransaction()) $db->rollBack(); throw $exception;
}
