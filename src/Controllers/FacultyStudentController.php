<?php
declare(strict_types=1);

namespace Codify\Controllers;

use Codify\Core\HttpException;
use Codify\Core\Request;
use Codify\Core\Response;
use Codify\Repositories\SystemSettingRepository;
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
    public function __construct(UserRepository $users, TokenRepository $tokens, SystemSettingRepository $settings, AuthGuard $guard) { $this->users = $users; $this->tokens = $tokens; $this->settings = $settings; $this->guard = $guard; }

    public function index(Request $request): void
    {
        $faculty = $this->guard->authenticate($request, true, 'faculty', true); $status = trim((string) $request->query('status', ''));
        if ($status !== '' && !in_array($status, ['active', 'inactive'], true)) throw new HttpException(422, 'The selected status is invalid.', ['status' => ['The selected status is invalid.']]);
        Response::success($this->users->paginatedStudents((int) $faculty['id'], trim((string) $request->query('search', '')), $status, max(1, (int) $request->query('page', 1)), min(100, max(1, (int) $request->query('per_page', 20)))));
    }

    public function show(Request $request): void
    {
        $faculty = $this->guard->authenticate($request, true, 'faculty', true); $student = $this->users->ownedStudent((int) $faculty['id'], $this->id($request));
        Response::success($this->users->payload($student));
    }

    public function store(Request $request): void
    {
        $faculty = $this->guard->authenticate($request, true, 'faculty', true); $data = $this->validated($request->json(), null); $settings = $this->settings->current();
        $student = $this->users->create(['faculty_id' => $faculty['id'], 'name' => $data['name'], 'username' => $data['username'], 'email' => $data['email'], 'password' => $this->hash($data['password']), 'role' => 'student', 'is_active' => $data['is_active'], 'must_change_password' => $settings['temporary_password_change_required']]);
        Response::success($this->users->payload($student), 'Student created successfully.', 201);
    }

    public function update(Request $request): void
    {
        $faculty = $this->guard->authenticate($request, true, 'faculty', true); $id = $this->id($request); $existing = $this->users->ownedStudent((int) $faculty['id'], $id); $data = $this->validated($request->json(), $existing); $attributes = ['name' => $data['name'], 'username' => $data['username'], 'email' => $data['email'], 'role' => 'student', 'is_active' => $data['is_active']];
        if ($data['password'] !== null) { $attributes['password'] = $this->hash($data['password']); $attributes['must_change_password'] = $this->settings->current()['temporary_password_change_required']; }
        $student = $this->users->update($id, $attributes);
        if ($data['password'] !== null || !$data['is_active']) $this->tokens->revokeAll($id);
        Response::success($this->users->payload($student), 'Student updated successfully.');
    }

    public function destroy(Request $request): void
    {
        $faculty = $this->guard->authenticate($request, true, 'faculty', true); $id = $this->id($request); $this->users->ownedStudent((int) $faculty['id'], $id); $this->tokens->revokeAll($id); $this->users->delete($id);
        Response::success([], 'Student deleted successfully.');
    }

    private function validated(array $input, ?array $existing): array
    {
        $v = new Validator($input); $name = $v->requiredString('name', 255); $username = $v->optionalString('username', 100); $email = $v->email('email'); $active = $v->boolean('is_active', $existing ? (bool) $existing['is_active'] : true); $password = $v->password($existing === null); $v->throwIfFailed(); $this->users->assertUnique($username, $email, $existing['id'] ?? null);
        return ['name' => $name, 'username' => $username, 'email' => $email, 'is_active' => $active, 'password' => $password];
    }
    private function id(Request $request): int { $id = filter_var($request->route('student'), FILTER_VALIDATE_INT); if (!$id || $id < 1) throw new HttpException(404, 'Student not found.'); return (int) $id; }
    private function hash(string $password): string { return password_hash($password, PASSWORD_BCRYPT, ['cost' => max(10, min(14, (int) env('BCRYPT_ROUNDS', '12')))]); }
}
