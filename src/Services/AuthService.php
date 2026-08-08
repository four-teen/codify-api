<?php
declare(strict_types=1);

namespace Codify\Services;

use Codify\Core\HttpException;
use Codify\Repositories\SystemSettingRepository;
use Codify\Repositories\TokenRepository;
use Codify\Repositories\UserRepository;

final class AuthService
{
    private $users;
    private $tokens;
    private $settings;
    private $limiter;

    public function __construct(UserRepository $users, TokenRepository $tokens, SystemSettingRepository $settings, LoginRateLimiter $limiter)
    {
        $this->users = $users; $this->tokens = $tokens; $this->settings = $settings; $this->limiter = $limiter;
    }

    public function login(string $login, string $password, string $ip): array
    {
        $settings = $this->settings->current();
        $limit = max(3, min(20, (int) $settings['max_failed_login_attempts']));
        $this->limiter->assertAllowed($login, $ip, $limit);
        $this->limiter->hit($login, $ip);
        $user = $this->users->findByLogin($login);
        if (!$user || !password_verify($password, (string) $user['password'])) {
            throw new HttpException(401, 'The supplied credentials are invalid.');
        }
        if (!$user['is_active']) throw new HttpException(403, 'This account is inactive.');
        if ($settings['maintenance_mode'] && $user['role'] !== 'administrator') throw new HttpException(503, 'Codify is temporarily unavailable while system maintenance is in progress.', [], 'MAINTENANCE_MODE');
        $this->limiter->clear($login, $ip);
        if (password_needs_rehash((string) $user['password'], PASSWORD_BCRYPT, ['cost' => $this->bcryptCost()])) {
            $this->users->rehashPassword((int) $user['id'], password_hash($password, PASSWORD_BCRYPT, ['cost' => $this->bcryptCost()]));
        }
        $token = $this->tokens->issue((int) $user['id'], max(15, min(1440, (int) $settings['session_timeout_minutes'])));
        return ['token' => $token, 'user' => $this->users->payload($user)];
    }

    public function changePassword(array $user, string $currentPassword, string $newPassword): void
    {
        if (!password_verify($currentPassword, (string) $user['password'])) throw new HttpException(422, 'The current password is incorrect.', ['current_password' => ['The current password is incorrect.']]);
        $this->users->updatePassword((int) $user['id'], password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => $this->bcryptCost()]));
        $this->tokens->revokeOthers((int) $user['id'], (int) $user['_token_id']);
    }

    private function bcryptCost(): int { return max(10, min(14, (int) env('BCRYPT_ROUNDS', '12'))); }
}
