<?php
declare(strict_types=1);

namespace Codify\Controllers;

use Codify\Core\HttpException;
use Codify\Core\Request;
use Codify\Core\Response;
use Codify\Repositories\FacultyScopeRepository;
use Codify\Repositories\FacultyAdministrationRepository;
use Codify\Repositories\SystemSettingRepository;
use Codify\Repositories\TokenRepository;
use Codify\Repositories\UserRepository;
use Codify\Services\AuthGuard;
use Codify\Services\SyllabusStorageService;
use Codify\Support\Validator;

final class FacultyManagementController
{
    private $users; private $scopes; private $administration; private $tokens; private $settings; private $guard; private $syllabus;
    public function __construct(UserRepository $users, FacultyScopeRepository $scopes, FacultyAdministrationRepository $administration, TokenRepository $tokens, SystemSettingRepository $settings, AuthGuard $guard, SyllabusStorageService $syllabus)
    { $this->users = $users; $this->scopes = $scopes; $this->administration = $administration; $this->tokens = $tokens; $this->settings = $settings; $this->guard = $guard; $this->syllabus = $syllabus; }

    public function index(Request $request): void
    {
        $this->guard->authenticate($request, true, 'administrator');
        $status = trim((string) $request->query('status', ''));
        if ($status !== '' && !in_array($status, ['active', 'inactive'], true)) throw new HttpException(422, 'The selected status is invalid.');
        $campus = $request->query('campus_id'); $campusId = null;
        if ($campus !== null && $campus !== '') { $campusId = filter_var($campus, FILTER_VALIDATE_INT); if ($campusId === false || $campusId < 1) throw new HttpException(422, 'The campus filter is invalid.'); }
        Response::success($this->scopes->paginatedFaculty(trim((string) $request->query('search', '')), $status, $campusId === null ? null : (int) $campusId, max(1, (int) $request->query('page', 1)), min(100, max(1, (int) $request->query('per_page', 20)))));
    }

    public function show(Request $request): void
    {
        $this->guard->authenticate($request, true, 'administrator');
        Response::success($this->scopes->facultyPayload($this->faculty($this->id($request))));
    }

    public function dashboard(Request $request): void
    {
        $this->guard->authenticate($request, true, 'administrator'); $faculty = $this->faculty($this->id($request));
        $page = max(1, (int) $request->query('activity_page', 1)); $perPage = min(100, max(10, (int) $request->query('activity_per_page', 50)));
        $dashboard = $this->administration->dashboard((int) $faculty['id'], $page, $perPage);
        Response::success(array_merge(['faculty' => $this->scopes->facultyPayload($faculty)], $dashboard));
    }

    public function store(Request $request): void
    {
        $this->guard->authenticate($request, true, 'administrator'); $input = $request->json(); $account = $this->validatedAccount($input, null);
        $scope = $this->scopes->validateAssignments($input['campus_id'] ?? null, $input['college_ids'] ?? null, $input['program_ids'] ?? null);
        $mustChange = $this->settings->current()['temporary_password_change_required'];
        $user = $this->scopes->transaction(function () use ($account, $scope, $mustChange) {
            $created = $this->users->create(['faculty_id' => null, 'name' => $account['name'], 'username' => $account['username'], 'email' => $account['email'], 'password' => $this->hash($account['password']), 'role' => 'faculty', 'is_active' => $account['is_active'], 'must_change_password' => $mustChange]);
            $this->scopes->syncFaculty((int) $created['id'], $scope['campus_id'], $scope['college_ids'], $scope['program_ids']); return $created;
        });
        Response::success($this->scopes->facultyPayload($user), 'Faculty account created successfully.', 201);
    }

    public function update(Request $request): void
    {
        $this->guard->authenticate($request, true, 'administrator'); $id = $this->id($request); $existing = $this->faculty($id); $input = $request->json();
        $account = $this->validatedAccount($input, $existing); $scope = $this->scopes->validateAssignments($input['campus_id'] ?? null, $input['college_ids'] ?? null, $input['program_ids'] ?? null);
        $this->scopes->assertStudentsWithinPrograms($id, $scope['program_ids']);
        $attributes = ['name' => $account['name'], 'username' => $account['username'], 'email' => $account['email'], 'role' => 'faculty', 'is_active' => $account['is_active']];
        if ($account['password'] !== null) { $attributes['password'] = $this->hash($account['password']); $attributes['must_change_password'] = $this->settings->current()['temporary_password_change_required']; }
        $user = $this->scopes->transaction(function () use ($id, $attributes, $scope) { $updated = $this->users->update($id, $attributes); $this->scopes->syncFaculty($id, $scope['campus_id'], $scope['college_ids'], $scope['program_ids']); return $updated; });
        if ($account['password'] !== null || !$account['is_active']) $this->tokens->revokeAll($id);
        Response::success($this->scopes->facultyPayload($user), 'Faculty account updated successfully.');
    }

    public function destroy(Request $request): void
    {
        $this->guard->authenticate($request, true, 'administrator'); $id = $this->id($request); $faculty = $this->faculty($id);
        $this->confirm($request, (string) $faculty['email']);
        $result = $this->administration->deleteFaculty($id, (string) $faculty['email']); $this->deleteSyllabi($result['stored_names']);
        unset($result['stored_names']); Response::success($result, 'Faculty account and all related records were deleted successfully.');
    }

    public function destroyStudents(Request $request): void
    {
        $this->guard->authenticate($request, true, 'administrator'); $id = $this->id($request); $this->faculty($id);
        $this->confirm($request, 'DELETE STUDENTS');
        $summary = $this->administration->deleteStudents($id);
        Response::success(['summary' => $summary], $summary['students'] . ' student account' . ($summary['students'] === 1 ? '' : 's') . ' and related records deleted.');
    }

    public function destroySubjects(Request $request): void
    {
        $this->guard->authenticate($request, true, 'administrator'); $id = $this->id($request); $this->faculty($id);
        $this->confirm($request, 'DELETE SUBJECTS');
        $result = $this->administration->deleteSubjects($id); $this->deleteSyllabi($result['stored_names']);
        unset($result['stored_names']); $count = $result['summary']['subjects'];
        Response::success($result, $count . ' faculty subject' . ($count === 1 ? '' : 's') . ' and related records deleted. Student accounts remain available.');
    }

    public function destroySubject(Request $request): void
    {
        $this->guard->authenticate($request, true, 'administrator'); $id = $this->id($request); $this->faculty($id);
        $offering = filter_var($request->route('offering'), FILTER_VALIDATE_INT);
        if ($offering === false || $offering < 1) throw new HttpException(404, 'Faculty subject not found.');
        $result = $this->administration->deleteSubjects($id, (int) $offering); $this->deleteSyllabi($result['stored_names']);
        unset($result['stored_names']); Response::success($result, 'Faculty subject and all subject-related records deleted. Student accounts remain available.');
    }

    private function validatedAccount(array $input, ?array $existing): array
    {
        $v = new Validator($input); $name = $v->requiredString('name', 255); $username = $v->optionalString('username', 100); $email = $v->email('email');
        $active = $v->boolean('is_active', $existing ? (bool) $existing['is_active'] : true); $password = $v->password($existing === null);
        $v->throwIfFailed(); $this->users->assertUnique($username, $email, $existing['id'] ?? null);
        return ['name' => $name, 'username' => $username, 'email' => $email, 'is_active' => $active, 'password' => $password];
    }
    private function faculty(int $id): array { $user = $this->users->find($id); if (!$user || $user['role'] !== 'faculty') throw new HttpException(404, 'Faculty account not found.'); return $user; }
    private function confirm(Request $request, string $expected): void { if (!hash_equals($expected, trim((string) ($request->json()['confirmation'] ?? '')))) throw new HttpException(422, 'The deletion confirmation does not match.'); }
    private function deleteSyllabi(array $storedNames): void { foreach ($storedNames as $storedName) $this->syllabus->delete((string) $storedName); }
    private function id(Request $request): int { $id = filter_var($request->route('faculty'), FILTER_VALIDATE_INT); if ($id === false || $id < 1) throw new HttpException(404, 'Faculty account not found.'); return (int) $id; }
    private function hash(string $password): string { return password_hash($password, PASSWORD_BCRYPT, ['cost' => max(10, min(14, (int) env('BCRYPT_ROUNDS', '12')))]); }
}
