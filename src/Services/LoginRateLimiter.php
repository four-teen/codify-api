<?php
declare(strict_types=1);

namespace Codify\Services;

use Codify\Core\HttpException;
use PDO;

final class LoginRateLimiter
{
    /** @var PDO */
    private $db;
    public function __construct(PDO $db) { $this->db = $db; }
    private function key(string $login, string $ip): string { return hash('sha256', strtolower(trim($login)) . '|' . $ip); }

    public function assertAllowed(string $login, string $ip, int $limit): void
    {
        $this->db->exec("DELETE FROM login_attempts WHERE attempted_at < (NOW() - INTERVAL 1 MINUTE)");
        $statement = $this->db->prepare('SELECT COUNT(*) FROM login_attempts WHERE attempt_key = :key AND attempted_at >= (NOW() - INTERVAL 1 MINUTE)');
        $statement->execute(['key' => $this->key($login, $ip)]);
        if ((int) $statement->fetchColumn() >= $limit) throw new HttpException(429, 'Too many login attempts. Please wait one minute and try again.');
    }

    public function hit(string $login, string $ip): void
    {
        $this->db->prepare('INSERT INTO login_attempts (attempt_key, attempted_at) VALUES (:key, NOW())')->execute(['key' => $this->key($login, $ip)]);
    }

    public function clear(string $login, string $ip): void
    {
        $this->db->prepare('DELETE FROM login_attempts WHERE attempt_key = :key')->execute(['key' => $this->key($login, $ip)]);
    }
}
