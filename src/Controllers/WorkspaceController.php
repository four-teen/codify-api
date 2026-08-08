<?php
declare(strict_types=1);

namespace Codify\Controllers;

use Codify\Core\Request;
use Codify\Core\Response;
use Codify\Repositories\UserRepository;
use Codify\Services\AuthGuard;

final class WorkspaceController
{
    private $guard;
    private $users;
    private $config;
    public function __construct(AuthGuard $guard, UserRepository $users, array $config) { $this->guard = $guard; $this->users = $users; $this->config = $config; }

    public function show(Request $request, string $role): void
    {
        $user = $this->guard->authenticate($request, true, $role);
        Response::success(['role' => $role, 'user' => ['id' => $user['id'], 'name' => $user['name']], 'modules' => $this->config['workspaces'][$role] ?? []]);
    }
}
