<?php
declare(strict_types=1);

namespace Codify\Repositories;

use PDO;

final class StudentLoginEventRepository
{
    /** @var PDO */
    private $db;

    public function __construct(PDO $db) { $this->db = $db; }

    public function record(int $studentId, int $tokenId, int $retentionDays = 90): void
    {
        $retentionDays = max(30, min(365, $retentionDays));
        $ownsTransaction = !$this->db->inTransaction();
        if ($ownsTransaction) $this->db->beginTransaction();
        try {
            $statement = $this->db->prepare('INSERT INTO student_login_events (student_id, access_token_id, occurred_at) VALUES (:student, :token, NOW())');
            $statement->execute(['student' => $studentId, 'token' => $tokenId]);
            $this->prune($retentionDays);
            if ($ownsTransaction) $this->db->commit();
        } catch (\Throwable $exception) {
            if ($ownsTransaction && $this->db->inTransaction()) $this->db->rollBack();
            throw $exception;
        }
    }

    public function prune(int $retentionDays): int
    {
        $retentionDays = max(30, min(365, $retentionDays));
        return (int) $this->db->exec('DELETE FROM student_login_events WHERE occurred_at < DATE_SUB(NOW(), INTERVAL ' . $retentionDays . ' DAY)');
    }

    public function recent(int $studentId, int $limit = 100): array
    {
        $limit = max(1, min(100, $limit));
        $sql = 'SELECT l.id, l.occurred_at,
            d.device_label, d.browser_label, d.os_label, d.device_type,
            e.match_status
            FROM student_login_events l
            LEFT JOIN student_device_events e ON e.id = l.source_device_event_id
            LEFT JOIN student_devices d ON d.id = e.device_id
            WHERE l.student_id = :student
            ORDER BY l.id DESC
            LIMIT ' . $limit;
        $statement = $this->db->prepare($sql);
        $statement->execute(['student' => $studentId]);
        $rows = $statement->fetchAll();
        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['device_verified'] = $row['device_label'] !== null;
        }
        unset($row);
        return $rows;
    }

    public function count(int $studentId): int
    {
        $statement = $this->db->prepare('SELECT COUNT(*) FROM student_login_events WHERE student_id = :student');
        $statement->execute(['student' => $studentId]);
        return (int) $statement->fetchColumn();
    }

    public function clear(int $studentId): int
    {
        $statement = $this->db->prepare('DELETE FROM student_login_events WHERE student_id = :student');
        $statement->execute(['student' => $studentId]);
        return $statement->rowCount();
    }
}
