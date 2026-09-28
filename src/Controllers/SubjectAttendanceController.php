<?php
declare(strict_types=1);

namespace Codify\Controllers;

use Codify\Core\HttpException;
use Codify\Core\Request;
use Codify\Core\Response;
use Codify\Repositories\SubjectAttendanceRepository;
use Codify\Services\AuthGuard;

final class SubjectAttendanceController
{
    private $attendance;
    private $guard;

    public function __construct(SubjectAttendanceRepository $attendance, AuthGuard $guard)
    { $this->attendance = $attendance; $this->guard = $guard; }

    private function offeringId(Request $request): int
    {
        $id = filter_var($request->route('offering'), FILTER_VALIDATE_INT);
        if ($id === false || $id < 1) throw new HttpException(404, 'Faculty subject not found.');
        return (int) $id;
    }

    public function show(Request $request): void
    {
        $faculty = $this->guard->authenticate($request, true, 'faculty');
        $date = $request->query('date', '');
        if (!is_string($date)) throw new HttpException(422, 'Choose a valid attendance date.');
        Response::success($this->attendance->sheet((int) $faculty['id'], $this->offeringId($request), $date));
    }

    public function history(Request $request): void
    {
        $faculty = $this->guard->authenticate($request, true, 'faculty');
        Response::success($this->attendance->history((int) $faculty['id'], $this->offeringId($request)));
    }

    public function save(Request $request): void
    {
        $faculty = $this->guard->authenticate($request, true, 'faculty');
        Response::success($this->attendance->save((int) $faculty['id'], $this->offeringId($request), $request->json()), 'Attendance saved.');
    }
}
