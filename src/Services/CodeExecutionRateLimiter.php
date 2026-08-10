<?php
declare(strict_types=1);

namespace Codify\Services;

use Codify\Core\HttpException;
use PDO;

final class CodeExecutionRateLimiter
{
    /** @var PDO */
    private $db;
    public function __construct(PDO $db) { $this->db = $db; }

    public function assertAllowed(int $studentId, string $ip, int $limit): void
    {
        $this->db->exec('DELETE FROM code_execution_attempts WHERE attempted_at < (NOW() - INTERVAL 1 HOUR)');
        $statement = $this->db->prepare('SELECT COUNT(*) FROM code_execution_attempts WHERE attempt_key = :key AND attempted_at >= (NOW() - INTERVAL 1 MINUTE)');
        $statement->execute(['key' => $this->key($studentId, $ip)]);
        if ((int) $statement->fetchColumn() >= $limit) {
            throw new HttpException(429, 'Too many code runs. Wait one minute before running again.', [], 'CODE_RUN_RATE_LIMITED');
        }
    }

    public function hit(int $studentId, string $ip): void
    {
        $statement = $this->db->prepare('INSERT INTO code_execution_attempts (student_id, attempt_key, attempted_at) VALUES (:student, :key, NOW())');
        $statement->execute(['student' => $studentId, 'key' => $this->key($studentId, $ip)]);
    }

    private function key(int $studentId, string $ip): string { return hash('sha256', $studentId . '|' . trim($ip)); }
}
