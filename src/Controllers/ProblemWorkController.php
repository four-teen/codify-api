<?php
declare(strict_types=1);

namespace Codify\Controllers;

use Codify\Core\HttpException;
use Codify\Core\Request;
use Codify\Core\Response;
use Codify\Repositories\ProblemWorkRepository;
use Codify\Repositories\StudentLearningRepository;
use Codify\Repositories\SystemSettingRepository;
use Codify\Services\AuthGuard;

final class ProblemWorkController
{
    private $work;
    private $learning;
    private $settings;
    private $guard;
    public function __construct(ProblemWorkRepository $work, StudentLearningRepository $learning, SystemSettingRepository $settings, AuthGuard $guard)
    { $this->work = $work; $this->learning = $learning; $this->settings = $settings; $this->guard = $guard; }

    public function state(Request $request): void
    {
        [$student, $problem] = $this->scope($request);
        Response::success($this->work->state($student, $problem));
    }

    public function act(Request $request): void
    {
        [$student, $problem] = $this->scope($request);
        $action = (string) $request->route('action');
        if (!in_array($action, ['start', 'close', 'heartbeat', 'submit'], true)) throw new HttpException(404, 'Coding action not found.');
        Response::success($this->work->act($student, $problem, $action, $request->json()));
    }

    public function responses(Request $request): void
    {
        $faculty = $this->guard->authenticate($request, true, 'faculty');
        Response::success($this->work->responses((int) $faculty['id'], (int) $request->route('problem')));
    }

    private function scope(Request $request): array
    {
        $student = $this->guard->authenticate($request, true, 'student');
        $problem = filter_var($request->route('problem'), FILTER_VALIDATE_INT);
        if (!$problem || $problem < 1) throw new HttpException(404, 'Python problem not found.');
        $term = $this->settings->current();
        $this->learning->problem((int) $student['id'], (int) $problem, $term['academic_year'], $term['academic_term']);
        return [(int) $student['id'], (int) $problem];
    }
}
