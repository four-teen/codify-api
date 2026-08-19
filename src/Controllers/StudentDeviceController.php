<?php
declare(strict_types=1);

namespace Codify\Controllers;

use Codify\Core\HttpException;
use Codify\Core\Request;
use Codify\Core\Response;
use Codify\Repositories\DeviceConsistencyRepository;
use Codify\Repositories\SystemSettingRepository;
use Codify\Repositories\TokenRepository;
use Codify\Services\AuthGuard;
use Codify\Services\DeviceCredentialVerifier;
use Codify\Services\DeviceFingerprintService;

final class StudentDeviceController
{
    private $devices;
    private $settings;
    private $tokens;
    private $guard;
    private $fingerprints;
    private $credentials;

    public function __construct(DeviceConsistencyRepository $devices, SystemSettingRepository $settings, TokenRepository $tokens, AuthGuard $guard, DeviceFingerprintService $fingerprints, DeviceCredentialVerifier $credentials)
    {
        $this->devices = $devices; $this->settings = $settings; $this->tokens = $tokens; $this->guard = $guard;
        $this->fingerprints = $fingerprints; $this->credentials = $credentials;
    }

    public function overview(Request $request): void
    {
        $student = $this->student($request); $settings = $this->settings->current();
        if ($settings['device_consistency_enabled']) $this->devices->prune((int) $settings['device_retention_days']);
        Response::success($this->overviewData((int) $student['id'], $settings));
    }

    public function consent(Request $request): void
    {
        $student = $this->student($request); $settings = $this->settings->current();
        $this->assertEnabled($settings);
        $studentId = (int) $student['id'];
        $this->devices->recordConsent($studentId, (string) $settings['device_policy_version'], 'granted', $this->noticeHash($settings), $this->fingerprints->networkHash($studentId, $request->ip()));
        Response::success($this->overviewData($studentId, $settings), 'Device consistency is now active for your account.');
    }

    public function decline(Request $request): void
    {
        $student = $this->student($request); $settings = $this->settings->current(); $this->assertEnabled($settings);
        $studentId = (int) $student['id'];
        $this->devices->recordConsent($studentId, (string) $settings['device_policy_version'], 'withdrawn', $this->noticeHash($settings), $this->fingerprints->networkHash($studentId, $request->ip()));
        $this->tokens->delete((int) $student['_token_id']);
        Response::success(['signed_out' => true], 'The notice was declined and the session was signed out.');
    }

    public function withdraw(Request $request): void
    {
        $student = $this->student($request); $settings = $this->settings->current(); $studentId = (int) $student['id'];
        $this->devices->recordConsent($studentId, (string) $settings['device_policy_version'], 'withdrawn', $this->noticeHash($settings), $this->fingerprints->networkHash($studentId, $request->ip()));
        $this->devices->deleteStudentData($studentId);
        Response::success($this->overviewData($studentId, $settings), 'Device-consistency collection was withdrawn and stored device reports were removed.');
    }

    public function register(Request $request): void
    {
        $student = $this->student($request); $settings = $this->readySettings((int) $student['id']); $input = $request->json();
        $studentId = (int) $student['id']; $credentialId = $this->credentialId($input); $signals = $this->signals($input);
        $publicKey = $this->credentials->normalizeJwk(is_array($input['public_key_jwk'] ?? null) ? $input['public_key_jwk'] : []);
        $existing = $this->devices->findAnyByCredential($credentialId);
        if ($existing) {
            if ((int) $existing['student_id'] !== $studentId) throw new HttpException(409, 'This browser identifier is already registered to another account.');
            if (in_array($existing['status'], ['revoked', 'reported'], true)) throw new HttpException(409, 'This browser record cannot be reused. Reset its stored Codify data and try again.', [], 'DEVICE_RECORD_INACTIVE');
            $upgraded = false;
            if ($this->devices->usesBrowserSignals($existing)) {
                $this->devices->promoteToBrowserKey($studentId, (int) $existing['id'], $publicKey);
                $upgraded = true;
            } elseif (!hash_equals((string) $existing['public_key_jwk'], $publicKey)) {
                throw new HttpException(409, 'This browser security key does not match its registered key.', [], 'DEVICE_KEY_MISMATCH');
            }
            Response::success(['registered' => false, 'upgraded' => $upgraded, 'requires_verification' => true, 'overview' => $this->overviewData($studentId, $settings)]);
        }
        $fingerprint = $this->fingerprints->build($studentId, $signals);
        $first = $this->devices->deviceCount($studentId) === 0;
        $possibleReset = $this->devices->findByFingerprint($studentId, $fingerprint['fingerprint_hash']) !== null;
        $device = $this->devices->createDevice($studentId, $credentialId, $publicKey, $fingerprint, $first ? 'recognized' : 'new');
        $this->devices->recordSession($studentId, (int) $device['id'], (int) $student['_token_id'], $first ? 'first_seen' : 'new', [], $this->fingerprints->networkHash($studentId, $request->ip()), $fingerprint);
        Response::success(['registered' => true, 'possible_browser_reset' => $possibleReset, 'overview' => $this->overviewData($studentId, $settings)], $first ? 'This is your first recognized device.' : 'A new device was recorded for your account.', 201);
    }

    public function observe(Request $request): void
    {
        $student = $this->student($request); $settings = $this->readySettings((int) $student['id']); $input = $request->json();
        $studentId = (int) $student['id']; $tokenId = (int) $student['_token_id'];
        $credentialId = $this->credentialId($input); $fingerprint = $this->fingerprints->build($studentId, $this->signals($input));
        $existing = $this->devices->findAnyByCredential($credentialId);
        if ($existing) {
            if ((int) $existing['student_id'] !== $studentId) throw new HttpException(409, 'This browser identifier is already registered to another account.');
            if (!$this->devices->usesBrowserSignals($existing)) throw new HttpException(409, 'This browser already has a protected security key.', [], 'DEVICE_KEY_REQUIRED');
            if (in_array($existing['status'], ['revoked', 'reported'], true)) throw new HttpException(409, 'This browser record cannot be reused. Reset its stored Codify data and try again.', [], 'DEVICE_RECORD_INACTIVE');
            $changed = hash_equals((string) $existing['fingerprint_hash'], (string) $fingerprint['fingerprint_hash']) ? [] : $this->fingerprints->changedComponents((string) $existing['component_hashes'], $fingerprint['component_hashes']);
            $match = $existing['status'] === 'new' ? 'new' : ($changed === [] ? 'recognized' : 'changed');
            $this->devices->recordSession($studentId, (int) $existing['id'], $tokenId, $match, $changed, $this->fingerprints->networkHash($studentId, $request->ip()), $fingerprint);
            Response::success(['registered' => false, 'verification_method' => 'browser_signals', 'overview' => $this->overviewData($studentId, $settings)], 'This browser was recorded in compatibility mode.');
        }
        $first = $this->devices->deviceCount($studentId) === 0;
        $possibleReset = $this->devices->findByFingerprint($studentId, $fingerprint['fingerprint_hash']) !== null;
        $device = $this->devices->createObservedDevice($studentId, $credentialId, $fingerprint, $first ? 'recognized' : 'new');
        $this->devices->recordSession($studentId, (int) $device['id'], $tokenId, $first ? 'first_seen' : 'new', [], $this->fingerprints->networkHash($studentId, $request->ip()), $fingerprint);
        Response::success(['registered' => true, 'possible_browser_reset' => $possibleReset, 'verification_method' => 'browser_signals', 'overview' => $this->overviewData($studentId, $settings)], $first ? 'This browser was recorded as the first observed device.' : 'A new browser was recorded in compatibility mode.', 201);
    }

    public function challenge(Request $request): void
    {
        $student = $this->student($request); $this->readySettings((int) $student['id']); $input = $request->json();
        $credentialId = $this->credentialId($input); $device = $this->devices->findOwnedByCredential((int) $student['id'], $credentialId);
        if (!$device || in_array($device['status'], ['revoked', 'reported'], true)) throw new HttpException(404, 'Active recognized device not found.');
        Response::success($this->devices->createChallenge((int) $student['id'], (int) $device['id'], (int) $student['_token_id']));
    }

    public function verify(Request $request): void
    {
        $student = $this->student($request); $settings = $this->readySettings((int) $student['id']); $input = $request->json();
        $studentId = (int) $student['id']; $tokenId = (int) $student['_token_id']; $credentialId = $this->credentialId($input);
        $challengeId = $this->inputString($input, 'challenge_id', 64); $signature = $this->inputString($input, 'signature', 200);
        if (preg_match('/^[a-f0-9]{64}$/', $challengeId) !== 1) throw new HttpException(422, 'The device verification challenge is invalid.');
        $device = $this->devices->findOwnedByCredential($studentId, $credentialId);
        if (!$device || in_array($device['status'], ['revoked', 'reported'], true)) throw new HttpException(404, 'Active recognized device not found.');
        $challenge = $this->devices->consumeChallenge($challengeId, $studentId, (int) $device['id'], $tokenId);
        $message = $this->credentials->message($challengeId, (string) $challenge['challenge'], $credentialId);
        if (!$this->credentials->verify((string) $device['public_key_jwk'], $message, $signature)) throw new HttpException(422, 'This browser could not prove possession of the registered device key.', [], 'DEVICE_KEY_VERIFICATION_FAILED');
        $fingerprint = $this->fingerprints->build($studentId, $this->signals($input));
        $changed = hash_equals((string) $device['fingerprint_hash'], (string) $fingerprint['fingerprint_hash']) ? [] : $this->fingerprints->changedComponents((string) $device['component_hashes'], $fingerprint['component_hashes']);
        $match = $device['status'] === 'new' ? 'new' : ($changed === [] ? 'recognized' : 'changed');
        $this->devices->recordSession($studentId, (int) $device['id'], $tokenId, $match, $changed, $this->fingerprints->networkHash($studentId, $request->ip()), $fingerprint);
        Response::success(['match_status' => $match, 'overview' => $this->overviewData($studentId, $settings)]);
    }

    public function recognize(Request $request): void
    {
        $student = $this->student($request); $settings = $this->readySettings((int) $student['id']); $studentId = (int) $student['id'];
        $device = $this->devices->findOwnedDevice($studentId, $this->deviceId($request));
        if (in_array($device['status'], ['reported', 'revoked'], true)) throw new HttpException(422, 'A reported or removed device cannot be recognized again.');
        $this->devices->updateStatus($studentId, (int) $device['id'], 'recognized');
        $this->devices->recordAction($studentId, (int) $device['id'], (int) $student['_token_id'], 'device_recognized', 'recognized', $this->fingerprints->networkHash($studentId, $request->ip()));
        Response::success($this->overviewData($studentId, $settings), 'Device recognized.');
    }

    public function report(Request $request): void
    {
        $student = $this->student($request); $settings = $this->readySettings((int) $student['id']); $studentId = (int) $student['id']; $tokenId = (int) $student['_token_id'];
        $device = $this->devices->findOwnedDevice($studentId, $this->deviceId($request));
        if ($device['status'] === 'reported') throw new HttpException(422, 'A reported device remains in the security report until the retention period ends.');
        $tokenIds = $this->devices->tokenIdsForDevice($studentId, (int) $device['id']);
        $this->devices->updateStatus($studentId, (int) $device['id'], 'reported');
        $this->devices->recordAction($studentId, (int) $device['id'], $tokenId, 'device_reported', 'reported', $this->fingerprints->networkHash($studentId, $request->ip()));
        $currentRevoked = in_array($tokenId, $tokenIds, true); $this->tokens->revokeIds($studentId, $tokenIds);
        Response::success(['current_session_revoked' => $currentRevoked, 'overview' => $this->overviewData($studentId, $settings)], 'The device was reported and its observed sessions were revoked. Change your password if you do not recognize this activity.');
    }

    public function destroy(Request $request): void
    {
        $student = $this->student($request); $settings = $this->readySettings((int) $student['id']); $studentId = (int) $student['id']; $tokenId = (int) $student['_token_id'];
        $device = $this->devices->findOwnedDevice($studentId, $this->deviceId($request));
        if ($device['status'] === 'reported') throw new HttpException(422, 'A reported device remains in the security report until the retention period ends.');
        $tokenIds = $this->devices->tokenIdsForDevice($studentId, (int) $device['id']);
        $this->devices->updateStatus($studentId, (int) $device['id'], 'revoked');
        $this->devices->recordAction($studentId, (int) $device['id'], $tokenId, 'device_revoked', 'revoked', $this->fingerprints->networkHash($studentId, $request->ip()));
        $currentRevoked = in_array($tokenId, $tokenIds, true); $this->tokens->revokeIds($studentId, $tokenIds);
        Response::success(['current_session_revoked' => $currentRevoked, 'overview' => $this->overviewData($studentId, $settings)], 'Device removed and its observed sessions were revoked.');
    }

    private function overviewData(int $studentId, array $settings): array
    {
        $enabled = (bool) $settings['device_consistency_enabled']; $configured = $this->fingerprints->configured();
        $consent = $this->devices->latestConsent($studentId); $consented = $this->isConsented($settings, $consent);
        $devices = $enabled ? $this->devices->devices($studentId) : [];
        $summary = ['total' => count($devices), 'recognized' => 0, 'new' => 0, 'reported' => 0, 'revoked' => 0];
        foreach ($devices as $device) if (isset($summary[$device['status']])) $summary[$device['status']]++;
        $status = !$enabled ? 'disabled' : (!$configured ? 'unavailable' : (!$consented ? 'consent_required' : ($summary['reported'] > 0 ? 'reported' : ($summary['new'] > 0 ? 'new_device' : ($summary['recognized'] > 0 ? 'consistent' : 'no_devices')))));
        return [
            'enabled' => $enabled, 'configured' => $configured, 'consent_required' => (bool) $settings['device_consent_required'],
            'consented' => $consented, 'status' => $status, 'summary' => $summary, 'devices' => $devices,
            'events' => $enabled ? $this->devices->recentEvents($studentId) : [],
            'policy' => ['version' => (string) $settings['device_policy_version'], 'notice' => (string) ($settings['device_notice'] ?? ''), 'retention_days' => (int) $settings['device_retention_days']],
            'consent' => $consent,
        ];
    }

    private function readySettings(int $studentId): array
    {
        $settings = $this->settings->current(); $this->assertEnabled($settings);
        if (!$this->fingerprints->configured()) throw new HttpException(503, 'Device consistency is not configured on the server.', [], 'DEVICE_CONSISTENCY_UNAVAILABLE');
        if (!$this->isConsented($settings, $this->devices->latestConsent($studentId))) throw new HttpException(403, 'Acknowledge the device-consistency notice before recording a device.', [], 'DEVICE_CONSENT_REQUIRED');
        return $settings;
    }

    private function isConsented(array $settings, ?array $consent): bool
    {
        if (!(bool) $settings['device_consent_required']) return true;
        return $consent !== null && $consent['action'] === 'granted' && hash_equals((string) $settings['device_policy_version'], (string) $consent['policy_version']);
    }

    private function assertEnabled(array $settings): void
    {
        if (!(bool) $settings['device_consistency_enabled']) throw new HttpException(403, 'Device consistency is disabled by the administrator.', [], 'DEVICE_CONSISTENCY_DISABLED');
    }

    private function student(Request $request): array
    {
        return $this->guard->authenticate($request, true, 'student', false, true);
    }

    private function credentialId(array $input): string
    {
        $credentialId = $this->inputString($input, 'credential_id', 100);
        if (preg_match('/^[A-Za-z0-9_-]{40,100}$/', $credentialId) !== 1) throw new HttpException(422, 'The browser credential identifier is invalid.', ['credential_id' => ['Refresh Codify and try again.']]);
        return $credentialId;
    }

    private function signals(array $input): array
    {
        if (!isset($input['signals']) || !is_array($input['signals'])) throw new HttpException(422, 'The device report is required.', ['signals' => ['Allow standard browser information and try again.']]);
        return $input['signals'];
    }

    private function inputString(array $input, string $field, int $max): string
    {
        $value = $input[$field] ?? '';
        if (is_array($value) || is_object($value)) $value = '';
        $value = trim((string) $value);
        if ($value === '' || strlen($value) > $max) throw new HttpException(422, 'The supplied device verification data is invalid.', [$field => ['This field is required and must be valid.']]);
        return $value;
    }

    private function deviceId(Request $request): int
    {
        $id = filter_var($request->route('device'), FILTER_VALIDATE_INT);
        if ($id === false || $id < 1) throw new HttpException(404, 'Recognized device not found.');
        return (int) $id;
    }

    private function noticeHash(array $settings): string
    {
        return $this->fingerprints->noticeHash((string) $settings['device_policy_version'], (string) ($settings['device_notice'] ?? ''));
    }
}
