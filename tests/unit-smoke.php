<?php
declare(strict_types=1);

use Codify\Core\HttpException;
use Codify\Core\Request;
use Codify\Core\Router;
use Codify\Support\Validator;
use Codify\Services\DeviceFingerprintService;
use Codify\Services\DeviceCredentialVerifier;

require dirname(__DIR__) . '/bootstrap/autoload.php';

$validatorRejectedWeakPassword = false;
try {
    $weakPassword = str_repeat('x', 5);
    $validator = new Validator(['password' => $weakPassword, 'password_confirmation' => $weakPassword]);
    $validator->password(true);
    $validator->throwIfFailed();
} catch (HttpException $exception) {
    $validatorRejectedWeakPassword = $exception->status === 422;
}

if (!$validatorRejectedWeakPassword) {
    throw new RuntimeException('Weak-password validation smoke test failed.');
}

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/codify-api/api/v1/users/42';
$_SERVER['SCRIPT_NAME'] = '/codify-api/index.php';
$request = Request::capture();
$matchedId = null;
$router = new Router();
$router->get('/api/v1/users/{user}', static function (Request $request) use (&$matchedId): void {
    $matchedId = $request->route('user');
});
$router->dispatch($request);

if ($matchedId !== '42') {
    throw new RuntimeException('Parameterized-route smoke test failed.');
}

$signals = [
    'version' => '1', 'browser_family' => 'Chrome', 'browser_major' => '140', 'os_family' => 'Windows',
    'device_type' => 'desktop', 'platform' => 'Win32', 'timezone' => 'Asia/Manila', 'languages' => ['en-US'],
    'screen_bucket' => '1900x1100', 'pixel_ratio_bucket' => '1', 'color_depth' => '24',
    'hardware_concurrency_bucket' => 'up-to-8', 'device_memory_bucket' => 'up-to-8',
    'max_touch_points' => '0', 'storage_available' => true,
];
$fingerprints = new DeviceFingerprintService(str_repeat('test-key-', 8));
$first = $fingerprints->build(10, $signals); $second = $fingerprints->build(10, $signals); $otherStudent = $fingerprints->build(11, $signals);
if (!hash_equals($first['fingerprint_hash'], $second['fingerprint_hash']) || hash_equals($first['fingerprint_hash'], $otherStudent['fingerprint_hash'])) {
    throw new RuntimeException('Student-scoped device fingerprint smoke test failed.');
}
$changedSignals = $signals; $changedSignals['timezone'] = 'UTC'; $changed = $fingerprints->build(10, $changedSignals);
$changedComponents = $fingerprints->changedComponents(json_encode($first['component_hashes']), $changed['component_hashes']);
if (!in_array('timezone', $changedComponents, true)) throw new RuntimeException('Device-change explanation smoke test failed.');

$originalFingerprintKey = getenv('DEVICE_FINGERPRINT_KEY');
$managedKeyPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'codify-device-key-' . bin2hex(random_bytes(8));
try {
    putenv('DEVICE_FINGERPRINT_KEY=');
    $managedFingerprints = new DeviceFingerprintService(null, $managedKeyPath);
    $managedFirst = $managedFingerprints->build(10, $signals);
    $managedReloaded = new DeviceFingerprintService(null, $managedKeyPath);
    $managedSecond = $managedReloaded->build(10, $signals);
    if ($managedFingerprints->configurationSource() !== 'managed_file' || !is_file($managedKeyPath) || !hash_equals($managedFirst['fingerprint_hash'], $managedSecond['fingerprint_hash'])) {
        throw new RuntimeException('Managed device fingerprint key smoke test failed.');
    }
} finally {
    if (is_file($managedKeyPath)) unlink($managedKeyPath);
    if ($originalFingerprintKey === false) putenv('DEVICE_FINGERPRINT_KEY');
    else putenv('DEVICE_FINGERPRINT_KEY=' . $originalFingerprintKey);
}

$verifier = new DeviceCredentialVerifier();
$public = $verifier->normalizeJwk(['kty' => 'EC', 'crv' => 'P-256', 'x' => '9hh3343hyRH7u0lK91wyMkXLo_LwZ51gxbMWoQz0dBk', 'y' => 'rZuRHfZnLdauVyrjmI5FVFd7PvAuYl1HE7Oai-htS9A']);
$message = $verifier->message(str_repeat('a', 64), 'test-challenge', str_repeat('b', 43));
if (!$verifier->verify($public, $message, 'CoEa7ugWThmvkPEQrnySrNq6BV00J_EooB46JS3QbpQkaJEOhG8RIW-xzi2IISuxpN5fCR5bNHtlYTLYLDIz-A')) throw new RuntimeException('Browser device-key signature smoke test failed.');

echo "Unit smoke tests passed.\n";
