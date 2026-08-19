<?php
declare(strict_types=1);

namespace Codify\Controllers;

use Codify\Core\HttpException;
use Codify\Core\Request;
use Codify\Core\Response;
use Codify\Repositories\AdministratorAuditLogRepository;
use Codify\Repositories\AdministratorStudentAuditRepository;
use Codify\Repositories\DeviceConsistencyRepository;
use Codify\Repositories\SystemSettingRepository;
use Codify\Repositories\StudentLoginEventRepository;
use Codify\Repositories\TokenRepository;
use Codify\Services\AuthGuard;

final class AdministratorStudentAuditController
{
    private $students;
    private $audit;
    private $devices;
    private $loginEvents;
    private $tokens;
    private $settings;
    private $guard;

    public function __construct(AdministratorStudentAuditRepository $students, AdministratorAuditLogRepository $audit, DeviceConsistencyRepository $devices, StudentLoginEventRepository $loginEvents, TokenRepository $tokens, SystemSettingRepository $settings, AuthGuard $guard)
    {
        $this->students = $students;
        $this->audit = $audit;
        $this->devices = $devices;
        $this->loginEvents = $loginEvents;
        $this->tokens = $tokens;
        $this->settings = $settings;
        $this->guard = $guard;
    }

    public function index(Request $request): void
    {
        $this->administrator($request);
        $settings = $this->settings->current();
        $this->loginEvents->prune((int) $settings['device_retention_days']);
        $search = trim((string) $request->query('search', ''));
        $page = max(1, (int) $request->query('page', 1));
        $perPage = min(100, max(1, (int) $request->query('per_page', 25)));
        Response::success($this->students->paginatedStudents($search, $page, $perPage));
    }

    public function show(Request $request): void
    {
        $this->administrator($request);
        Response::success($this->dashboard($this->studentId($request)));
    }

    public function clearLoginEvents(Request $request): void
    {
        $administrator = $this->administrator($request);
        $studentId = $this->studentId($request);
        $this->students->transaction(function () use ($administrator, $studentId): void {
            $cleared = $this->loginEvents->clear($studentId);
            $deviceChecksCleared = $this->devices->clearSessionEvents($studentId);
            $this->audit->record((int) $administrator['id'], $studentId, 'student_login_events_cleared', 'Cleared recorded student logins and related device-login checks.', ['logins_cleared' => $cleared, 'device_checks_cleared' => $deviceChecksCleared]);
        });
        Response::success($this->dashboard($studentId), 'Recorded student logins and device-login checks were cleared. The administrator action remains in the audit trail.');
    }

    public function resetDevices(Request $request): void
    {
        $administrator = $this->administrator($request);
        $studentId = $this->studentId($request);
        $this->students->transaction(function () use ($administrator, $studentId): void {
            $deviceCount = $this->devices->deviceCount($studentId);
            $sessionCount = $this->tokens->activeCount($studentId);
            $this->tokens->revokeAll($studentId);
            $cleared = $this->devices->clearDevices($studentId);
            $this->audit->record((int) $administrator['id'], $studentId, 'student_devices_reset', 'Reset all recognized devices and revoked all student sessions.', [
                'devices_cleared' => $cleared,
                'devices_before_reset' => $deviceCount,
                'sessions_revoked' => $sessionCount,
            ]);
        });
        Response::success($this->dashboard($studentId), 'All recognized devices were reset and the student was signed out everywhere.');
    }

    public function destroyDevice(Request $request): void
    {
        $administrator = $this->administrator($request);
        $studentId = $this->studentId($request);
        $deviceId = $this->deviceId($request);
        $device = $this->devices->findOwnedDevice($studentId, $deviceId);
        $tokenIds = $this->devices->tokenIdsForDevice($studentId, $deviceId);
        $this->students->transaction(function () use ($administrator, $studentId, $deviceId, $device, $tokenIds): void {
            $this->tokens->revokeIds($studentId, $tokenIds);
            $this->devices->deleteDevice($studentId, $deviceId);
            $this->audit->record((int) $administrator['id'], $studentId, 'student_device_removed', 'Removed one recognized device and revoked its observed sessions.', [
                'device_id' => $deviceId,
                'device_label' => $device['device_label'],
                'device_status' => $device['status'],
                'sessions_revoked' => count($tokenIds),
            ]);
        });
        Response::success($this->dashboard($studentId), 'The recognized device and its verification events were removed. Successful authentication history was retained.');
    }

    public function revokeSessions(Request $request): void
    {
        $administrator = $this->administrator($request);
        $studentId = $this->studentId($request);
        $this->students->transaction(function () use ($administrator, $studentId): void {
            $sessionCount = $this->tokens->activeCount($studentId);
            $this->tokens->revokeAll($studentId);
            $this->audit->record((int) $administrator['id'], $studentId, 'student_sessions_revoked', 'Revoked all active student sessions.', ['sessions_revoked' => $sessionCount]);
        });
        Response::success($this->dashboard($studentId), 'All active sessions for this student were revoked.');
    }

    private function dashboard(int $studentId): array
    {
        $student = $this->students->student($studentId);
        $settings = $this->settings->current();
        $this->loginEvents->prune((int) $settings['device_retention_days']);
        $devices = $this->devices->devices($studentId);
        $summary = ['total' => count($devices), 'recognized' => 0, 'new' => 0, 'reported' => 0, 'revoked' => 0];
        foreach ($devices as $device) {
            if (isset($summary[$device['status']])) $summary[$device['status']]++;
        }
        $status = $summary['reported'] > 0 ? 'reported' : ($summary['new'] > 0 ? 'new_device' : ($summary['recognized'] > 0 ? 'consistent' : 'no_devices'));
        return [
            'student' => $student,
            'fingerprinting' => [
                'enabled' => (bool) $settings['device_consistency_enabled'],
                'status' => $status,
                'summary' => $summary,
                'devices' => $devices,
                'events' => $this->devices->recentEvents($studentId, 100),
                'consent' => $this->devices->latestConsent($studentId),
                'consent_history' => $this->devices->consentHistory($studentId, 50),
                'policy' => [
                    'version' => (string) $settings['device_policy_version'],
                    'retention_days' => (int) $settings['device_retention_days'],
                ],
            ],
            'active_sessions' => $this->tokens->activeCount($studentId),
            'login_summary' => ['total' => $this->loginEvents->count($studentId)],
            'login_events' => $this->loginEvents->recent($studentId, 100),
            'administrator_audit' => $this->audit->forStudent($studentId, 100),
        ];
    }

    private function administrator(Request $request): array
    {
        return $this->guard->authenticate($request, true, 'administrator');
    }

    private function studentId(Request $request): int
    {
        $id = filter_var($request->route('student'), FILTER_VALIDATE_INT);
        if ($id === false || $id < 1) throw new HttpException(404, 'Student account not found.');
        $this->students->student((int) $id);
        return (int) $id;
    }

    private function deviceId(Request $request): int
    {
        $id = filter_var($request->route('device'), FILTER_VALIDATE_INT);
        if ($id === false || $id < 1) throw new HttpException(404, 'Recognized device not found.');
        return (int) $id;
    }
}
