<?php
declare(strict_types=1);

namespace Codify\Controllers;

use Codify\Core\HttpException;
use Codify\Core\Request;
use Codify\Core\Response;
use Codify\Repositories\FacultyScopeRepository;
use Codify\Services\AuthGuard;

final class FacultySubjectController
{
    /** @var FacultyScopeRepository */
    private $scopes;
    /** @var AuthGuard */
    private $guard;

    public function __construct(FacultyScopeRepository $scopes, AuthGuard $guard)
    {
        $this->scopes = $scopes;
        $this->guard = $guard;
    }

    public function index(Request $request): void
    {
        $faculty = $this->guard->authenticate($request, true, 'faculty');
        $status = trim((string) $request->query('status', ''));
        if ($status !== '' && !in_array($status, ['active', 'inactive'], true)) {
            throw new HttpException(422, 'The selected status is invalid.', ['status' => ['The selected status is invalid.']]);
        }

        $programId = null;
        $programInput = trim((string) $request->query('program_id', ''));
        if ($programInput !== '') {
            $validated = filter_var($programInput, FILTER_VALIDATE_INT);
            if ($validated === false || $validated < 1) {
                throw new HttpException(422, 'The selected program is invalid.', ['program_id' => ['Select a valid assigned program.']]);
            }
            $programId = (int) $validated;
            $assignedPrograms = array_map(static function (array $program): int {
                return (int) $program['id'];
            }, $this->scopes->scope((int) $faculty['id'])['programs']);
            if (!in_array($programId, $assignedPrograms, true)) {
                throw new HttpException(403, 'The selected program is outside your assigned academic scope.');
            }
        }

        Response::success($this->scopes->paginatedSubjects(
            (int) $faculty['id'],
            trim((string) $request->query('search', '')),
            $status,
            $programId,
            max(1, (int) $request->query('page', 1)),
            min(100, max(1, (int) $request->query('per_page', 20)))
        ));
    }
}
