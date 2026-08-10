<?php
declare(strict_types=1);

namespace Codify\Controllers;

use Codify\Core\HttpException;
use Codify\Core\Request;
use Codify\Core\Response;
use Codify\Repositories\SystemSettingRepository;
use Codify\Repositories\FacultyScopeRepository;
use Codify\Repositories\TokenRepository;
use Codify\Repositories\UserRepository;
use Codify\Services\AuthGuard;
use Codify\Support\Validator;

final class FacultyStudentController
{
    private $users;
    private $tokens;
    private $settings;
    private $guard;
    private $scopes;
    public function __construct(UserRepository $users, FacultyScopeRepository $scopes, TokenRepository $tokens, SystemSettingRepository $settings, AuthGuard $guard) { $this->users = $users; $this->scopes = $scopes; $this->tokens = $tokens; $this->settings = $settings; $this->guard = $guard; }

    public function index(Request $request): void
    {
        $faculty = $this->guard->authenticate($request, true, 'faculty', true); $status = trim((string) $request->query('status', ''));
        if ($status !== '' && !in_array($status, ['active', 'inactive'], true)) throw new HttpException(422, 'The selected status is invalid.', ['status' => ['The selected status is invalid.']]);
        Response::success($this->scopes->paginatedStudents((int) $faculty['id'], trim((string) $request->query('search', '')), $status, max(1, (int) $request->query('page', 1)), min(100, max(1, (int) $request->query('per_page', 20)))));
    }

    public function show(Request $request): void
    {
        $faculty = $this->guard->authenticate($request, true, 'faculty', true); Response::success($this->scopes->ownedStudent((int) $faculty['id'], $this->id($request)));
    }

    public function store(Request $request): void
    {
        $faculty = $this->guard->authenticate($request, true, 'faculty', true); $data = $this->validated((int) $faculty['id'], $request->json(), null);
        $student = $this->scopes->transaction(function () use ($faculty, $data) { $created = $this->users->create(['faculty_id' => $faculty['id'], 'first_name' => $data['first_name'], 'last_name' => $data['last_name'], 'name' => $data['name'], 'username' => $data['username'], 'email' => $data['email'], 'password' => $this->hash($data['credential_local'] . '@1234'), 'role' => 'student', 'is_active' => $data['is_active'], 'must_change_password' => true]); $this->scopes->syncStudentProgram((int) $created['id'], $data['program_id']); return $created; });
        Response::success($this->scopes->ownedStudent((int) $faculty['id'], (int) $student['id']), 'Student created successfully.', 201);
    }

    public function update(Request $request): void
    {
        $faculty = $this->guard->authenticate($request, true, 'faculty', true); $id = $this->id($request); $existing = $this->scopes->ownedStudent((int) $faculty['id'], $id); $data = $this->validated((int) $faculty['id'], $request->json(), $existing); $attributes = ['first_name' => $data['first_name'], 'last_name' => $data['last_name'], 'name' => $data['name'], 'username' => $data['username'], 'email' => $data['email'], 'role' => 'student', 'is_active' => $data['is_active']];
        if ($data['password'] !== null) { $attributes['password'] = $this->hash($data['password']); $attributes['must_change_password'] = $this->settings->current()['temporary_password_change_required']; }
        $student = $this->scopes->transaction(function () use ($id, $attributes, $data) { $updated = $this->users->update($id, $attributes); $this->scopes->syncStudentProgram($id, $data['program_id']); return $updated; });
        if ($data['password'] !== null || !$data['is_active']) $this->tokens->revokeAll($id);
        Response::success($this->scopes->ownedStudent((int) $faculty['id'], (int) $student['id']), 'Student updated successfully.');
    }

    public function destroy(Request $request): void
    {
        $faculty = $this->guard->authenticate($request, true, 'faculty', true); $id = $this->id($request); $this->scopes->ownedStudent((int) $faculty['id'], $id); $this->tokens->revokeAll($id); $this->users->delete($id);
        Response::success([], 'Student deleted successfully.');
    }

    private function validated(int $facultyId, array $input, ?array $existing): array
    {
        $v = new Validator($input); $firstName = $v->requiredString('first_name', 100); $lastName = $v->requiredString('last_name', 100); $username = $v->optionalString('username', 100); $active = $v->boolean('is_active', $existing ? (bool) $existing['is_active'] : true); $password = $existing ? $v->password(false) : null; $programId = $v->integer('program_id', 1, PHP_INT_MAX, $existing ? (int) $existing['program_id'] : 0); $v->throwIfFailed(); $local = $this->credentialLocal($firstName, $lastName); $email = $local . '@sksu.edu.ph'; $this->users->assertUnique($username, $email, $existing['id'] ?? null); $this->scopes->assertProgramAccess($facultyId, $programId);
        return ['first_name' => $firstName, 'last_name' => $lastName, 'name' => trim($firstName . ' ' . $lastName), 'username' => $username, 'email' => $email, 'credential_local' => $local, 'is_active' => $active, 'password' => $password, 'program_id' => $programId];
    }
    private function credentialLocal(string $firstName, string $lastName): string
    {
        $name = $firstName . $lastName;
        if (function_exists('iconv')) {
            $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
            if ($ascii !== false) $name = $ascii;
        }
        $local = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '', $name));
        if ($local === '') throw new HttpException(422, 'The supplied names cannot generate institutional credentials.', ['first_name' => ['Use letters or numbers that can form account credentials.']]);
        return $local;
    }
    private function id(Request $request): int { $id = filter_var($request->route('student'), FILTER_VALIDATE_INT); if (!$id || $id < 1) throw new HttpException(404, 'Student not found.'); return (int) $id; }
    private function hash(string $password): string { return password_hash($password, PASSWORD_BCRYPT, ['cost' => max(10, min(14, (int) env('BCRYPT_ROUNDS', '12')))]); }
}
