<?php
declare(strict_types=1);
namespace Codify\Controllers;
use Codify\Core\Request;
use Codify\Core\Response;
use Codify\Repositories\ProblemRubricRepository;
use Codify\Services\AuthGuard;

final class ProblemRubricController
{
    private $rubrics; private $guard;
    public function __construct(ProblemRubricRepository $rubrics, AuthGuard $guard) { $this->rubrics = $rubrics; $this->guard = $guard; }
    public function templates(Request $request): void { $faculty = $this->guard->authenticate($request, true, 'faculty'); Response::success($this->rubrics->templates((int) $faculty['id'])); }
    public function saveTemplate(Request $request): void { $faculty = $this->guard->authenticate($request, true, 'faculty'); Response::success($this->rubrics->saveTemplate((int) $faculty['id'], $request->route('template') ? (int) $request->route('template') : null, $request->json()), 'Rubric template saved.'); }
    public function deleteTemplate(Request $request): void { $faculty = $this->guard->authenticate($request, true, 'faculty'); $this->rubrics->deleteTemplate((int) $faculty['id'], (int) $request->route('template')); Response::success([], 'Template deleted. Attached problem rubrics are preserved.'); }
    public function generate(Request $request): void { $faculty = $this->guard->authenticate($request, true, 'faculty'); Response::success($this->rubrics->generate((int) $faculty['id'], (int) $request->route('problem'), (int) $request->route('student')), 'Initial score generated.'); }
    public function finalize(Request $request): void { $faculty = $this->guard->authenticate($request, true, 'faculty'); Response::success($this->rubrics->finalize((int) $faculty['id'], (int) $request->route('problem'), (int) $request->route('student'), $request->json()), 'Final rubric score saved.'); }
}
