<?php
declare(strict_types=1);

namespace Codify\Services;

use Codify\Core\HttpException;
use Codify\Core\Request;
use Codify\Repositories\DeviceConsistencyRepository;
use Codify\Repositories\SystemSettingRepository;
use Codify\Repositories\TokenRepository;
use Codify\Repositories\UserRepository;

final class AuthGuard
{
    private $tokens;
    private $users;
    private $settings;
    private $devices;

    public function __construct(TokenRepository $tokens, UserRepository $users, SystemSettingRepository $settings, DeviceConsistencyRepository $devices)
    {
        $this->tokens = $tokens; $this->users = $users; $this->settings = $settings; $this->devices = $devices;
    }

    public function authenticate(Request $request, bool $passwordChanged = false, ?string $role = null, bool $facultyStudentsEnabled = false, bool $allowPendingDeviceConsent = false): array
    {
        $token = $this->tokens->resolve($request->bearerToken());
        if (!$token) throw new HttpException(401, 'Unauthenticated.');
        $user = $this->users->find((int) $token['tokenable_id']);
        if (!$user) { $this->tokens->delete((int) $token['id']); throw new HttpException(401, 'Unauthenticated.'); }
        if (!$user['is_active']) throw new HttpException(403, 'Account is inactive.');
        $settings = $this->settings->current();
        if ($settings['maintenance_mode'] && $user['role'] !== 'administrator') throw new HttpException(503, 'Codify is temporarily unavailable while system maintenance is in progress.', [], 'MAINTENANCE_MODE');
        if ($passwordChanged && $user['must_change_password']) throw new HttpException(409, 'Change the temporary password before accessing this resource.', [], 'PASSWORD_CHANGE_REQUIRED');
        if ($role !== null && $user['role'] !== $role) throw new HttpException(403, 'Forbidden.');
        if (!$allowPendingDeviceConsent && $user['role'] === 'student' && $settings['device_consistency_enabled'] && $settings['device_consent_required'] && !$this->devices->hasCurrentConsent((int) $user['id'], (string) $settings['device_policy_version'])) {
            throw new HttpException(403, 'Accept the device-use data notice before accessing the student workspace.', [], 'DEVICE_CONSENT_REQUIRED');
        }
        if ($facultyStudentsEnabled && !$settings['faculty_student_management_enabled']) throw new HttpException(403, 'Faculty student management is currently disabled by the administrator.', [], 'FACULTY_STUDENT_MANAGEMENT_DISABLED');
        $user['_token_id'] = (int) $token['id'];
        return $user;
    }
}
