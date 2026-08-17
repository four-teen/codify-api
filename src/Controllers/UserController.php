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

final class UserController
{
    private $users;
    private $tokens;
    private $settings;
    private $guard;
    public function __construct(UserRepository $users, TokenRepository $tokens, SystemSettingRepository $settings, AuthGuard $guard) { $this->users = $users; $this->tokens = $tokens; $this->settings = $settings; $this->guard = $guard; }

    public function index(Request $request): void
    {
        $this->guard->authenticate($request, true, 'administrator');
        Response::success($this->users->paginatedManaged(trim((string) $request->query('search', '')), 'administrator', max(1, (int) $request->query('page', 1)), min(100, max(1, (int) $request->query('per_page', 20)))));
    }

    public function show(Request $request): void
    {
        $this->guard->authenticate($request, true, 'administrator');
        $user = $this->administrator($this->id($request));
        Response::success($this->users->payload($user, true));
    }

    public function store(Request $request): void
    {
        $this->guard->authenticate($request, true, 'administrator'); $input = $request->json(); $data = $this->validated($input, null);
        $settings = $this->settings->current();
        $user = $this->users->create([
            'faculty_id' => null, 'name' => $data['name'], 'username' => $data['username'], 'email' => $data['email'],
            'password' => $this->hash($data['password']), 'role' => 'administrator', 'is_active' => $data['is_active'],
            'must_change_password' => $settings['temporary_password_change_required'],
        ], $data['permissions']);
        Response::success($this->users->payload($user, true), 'User created successfully.', 201);
    }

    public function update(Request $request): void
    {
        $actor = $this->guard->authenticate($request, true, 'administrator'); $id = $this->id($request); $existing = $this->administrator($id); $input = $request->json(); $data = $this->validated($input, $existing);
        if ((int) $actor['id'] === $id && !$data['is_active']) throw new HttpException(422, 'You cannot deactivate your own account.');
        if (!$data['is_active'] && $this->users->administratorCount(true, $id) === 0) throw new HttpException(422, 'At least one active administrator account is required.');
        $settings = $this->settings->current();
        $attributes = ['name' => $data['name'], 'username' => $data['username'], 'email' => $data['email'], 'role' => 'administrator', 'is_active' => $data['is_active']];
        if ($data['password'] !== null) { $attributes['password'] = $this->hash($data['password']); $attributes['must_change_password'] = $settings['temporary_password_change_required']; }
        $user = $this->users->update($id, $attributes, $data['permissions_provided'] ? $data['permissions'] : null);
        if ($data['password'] !== null || !$data['is_active']) $this->tokens->revokeAll($id);
        Response::success($this->users->payload($user, true), 'User updated successfully.');
    }

    public function destroy(Request $request): void
    {
        $actor = $this->guard->authenticate($request, true, 'administrator'); $id = $this->id($request); $user = $this->administrator($id);
        if ((int) $actor['id'] === $id) throw new HttpException(422, 'You cannot delete your own account.');
        if ($this->users->administratorCount(false) <= 1) throw new HttpException(422, 'At least one administrator account is required.');
        $this->tokens->revokeAll($id); $this->users->delete($id);
        Response::success([], 'User deleted successfully.');
    }

    private function validated(array $input, ?array $existing): array
    {
        $v = new Validator($input); $name = $v->requiredString('name', 255); $username = $v->optionalString('username', 100); $email = $v->email('email');
        $role = $v->oneOf('role', ['administrator'], 'administrator');
        $active = $v->boolean('is_active', $existing ? (bool) $existing['is_active'] : true); $password = $v->password($existing === null);
        $permissionsProvided = array_key_exists('permissions', $input); $permissions = [];
        if ($permissionsProvided) {
            if (!is_array($input['permissions'])) $v->add('permissions', 'The permissions field must be an array.');
            else $permissions = $this->users->validatePermissionCodes($input['permissions']);
        }
        $v->throwIfFailed(); $this->users->assertUnique($username, $email, $existing['id'] ?? null);
        return compact('name', 'username', 'email', 'role', 'active', 'password', 'permissions') + ['is_active' => $active, 'permissions_provided' => $permissionsProvided];
    }

    private function id(Request $request): int { $id = filter_var($request->route('user'), FILTER_VALIDATE_INT); if (!$id || $id < 1) throw new HttpException(404, 'User not found.'); return (int) $id; }
    private function administrator(int $id): array { $user = $this->users->find($id); if (!$user || $user['role'] !== 'administrator') throw new HttpException(404, 'Administrator account not found.'); return $user; }
    private function hash(string $password): string { return password_hash($password, PASSWORD_BCRYPT, ['cost' => max(10, min(14, (int) env('BCRYPT_ROUNDS', '12')))]); }
}
