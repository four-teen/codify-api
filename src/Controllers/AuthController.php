<?php
declare(strict_types=1);

namespace Codify\Controllers;

use Codify\Core\HttpException;
use Codify\Core\Request;
use Codify\Core\Response;
use Codify\Repositories\TokenRepository;
use Codify\Repositories\UserRepository;
use Codify\Services\AuthGuard;
use Codify\Services\AuthService;
use Codify\Support\Validator;

final class AuthController
{
    private $auth;
    private $guard;
    private $users;
    private $tokens;
    public function __construct(AuthService $auth, AuthGuard $guard, UserRepository $users, TokenRepository $tokens) { $this->auth = $auth; $this->guard = $guard; $this->users = $users; $this->tokens = $tokens; }

    public function login(Request $request): void
    {
        $input = $request->json(); $validator = new Validator($input);
        $login = $validator->requiredString('login', 255); $password = (string) ($input['password'] ?? '');
        if ($password === '') $validator->add('password', 'The password field is required.');
        $validator->throwIfFailed();
        Response::success($this->auth->login($login, $password, $request->ip()), 'Signed in successfully.');
    }

    public function me(Request $request): void
    {
        $user = $this->guard->authenticate($request);
        Response::success($this->users->payload($user));
    }

    public function logout(Request $request): void
    {
        $user = $this->guard->authenticate($request);
        $this->tokens->delete((int) $user['_token_id']);
        Response::success([], 'Signed out successfully.');
    }

    public function changePassword(Request $request): void
    {
        $user = $this->guard->authenticate($request); $input = $request->json(); $validator = new Validator($input);
        $current = (string) ($input['current_password'] ?? '');
        if ($current === '') $validator->add('current_password', 'The current password field is required.');
        $password = $validator->password(true); $validator->throwIfFailed();
        if ($password === null) throw new HttpException(422, 'A new password is required.');
        $this->auth->changePassword($user, $current, $password);
        Response::success([], 'Password changed successfully.');
    }
}
