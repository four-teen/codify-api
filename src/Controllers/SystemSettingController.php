<?php
declare(strict_types=1);

namespace Codify\Controllers;

use Codify\Core\Request;
use Codify\Core\Response;
use Codify\Repositories\SystemSettingRepository;
use Codify\Services\AuthGuard;
use Codify\Support\Validator;

final class SystemSettingController
{
    private $settings;
    private $guard;
    public function __construct(SystemSettingRepository $settings, AuthGuard $guard) { $this->settings = $settings; $this->guard = $guard; }
    public function publicShow(Request $request): void { Response::success($this->settings->publicPayload()); }
    public function show(Request $request): void { $this->guard->authenticate($request, true, 'administrator'); Response::success($this->settings->current()); }

    public function update(Request $request): void
    {
        $this->guard->authenticate($request, true, 'administrator'); $input = $request->json(); $current = $this->settings->current(); $v = new Validator($input); $values = [];
        if ($v->has('institution_name')) $values['institution_name'] = $v->requiredString('institution_name', 160);
        if ($v->has('institution_logo_url')) {
            $values['institution_logo_url'] = $v->optionalString('institution_logo_url', 2048);
            if ($values['institution_logo_url'] !== null && preg_match('#^/[A-Za-z0-9_./-]+$#', $values['institution_logo_url']) !== 1) $v->add('institution_logo_url', 'Use a local image path beginning with /.');
        }
        if ($v->has('timezone')) { $values['timezone'] = $v->requiredString('timezone', 80); if (!in_array($values['timezone'], timezone_identifiers_list(), true)) $v->add('timezone', 'The selected timezone is invalid.'); }
        if ($v->has('academic_year')) { $values['academic_year'] = $v->requiredString('academic_year', 20); if (preg_match('/^\d{4}-\d{4}$/', $values['academic_year']) !== 1) $v->add('academic_year', 'Use the academic year format YYYY-YYYY.'); }
        if ($v->has('academic_term')) $values['academic_term'] = $v->requiredString('academic_term', 100);
        foreach (['faculty_student_management_enabled', 'temporary_password_change_required', 'maintenance_mode', 'announcement_enabled'] as $field) if ($v->has($field)) $values[$field] = $v->boolean($field, (bool) $current[$field]);
        if ($v->has('session_timeout_minutes')) $values['session_timeout_minutes'] = $v->integer('session_timeout_minutes', 15, 1440, (int) $current['session_timeout_minutes']);
        if ($v->has('max_failed_login_attempts')) $values['max_failed_login_attempts'] = $v->integer('max_failed_login_attempts', 3, 20, (int) $current['max_failed_login_attempts']);
        if ($v->has('announcement_message')) $values['announcement_message'] = $v->optionalString('announcement_message', 500);
        $announcementEnabled = $values['announcement_enabled'] ?? $current['announcement_enabled'];
        $announcementMessage = array_key_exists('announcement_message', $values) ? $values['announcement_message'] : $current['announcement_message'];
        if ($announcementEnabled && !$announcementMessage) $v->add('announcement_message', 'An announcement message is required when the banner is enabled.');
        $v->throwIfFailed();
        Response::success($this->settings->update($values), 'System settings updated successfully.');
    }
}
