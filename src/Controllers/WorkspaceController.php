<?php
declare(strict_types=1);

namespace Codify\Controllers;

use Codify\Core\Request;
use Codify\Core\Response;
use Codify\Repositories\UserRepository;
use Codify\Repositories\FacultyScopeRepository;
use Codify\Services\AuthGuard;

final class WorkspaceController
{
    private $guard;
    private $users;
    private $config;
    private $scopes;
    public function __construct(AuthGuard $guard, UserRepository $users, FacultyScopeRepository $scopes, array $config) { $this->guard = $guard; $this->users = $users; $this->scopes = $scopes; $this->config = $config; }

    public function show(Request $request, string $role): void
    {
        $user = $this->guard->authenticate($request, true, $role);
        $payload = ['role' => $role, 'user' => ['id' => $user['id'], 'name' => $user['name']], 'modules' => $this->config['workspaces'][$role] ?? []];
        if ($role === 'faculty') { $payload['scope'] = $this->scopes->scope((int) $user['id']); $payload['metrics'] = $this->scopes->studentMetrics((int) $user['id']); }
        Response::success($payload);
    }
}
