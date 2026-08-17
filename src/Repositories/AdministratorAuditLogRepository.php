<?php
declare(strict_types=1);

namespace Codify\Repositories;

use PDO;

final class AdministratorAuditLogRepository
{
    /** @var PDO */
    private $db;

    public function __construct(PDO $db) { $this->db = $db; }

    public function record(int $actorId, int $studentId, string $action, string $summary, array $metadata = []): void
    {
        $statement = $this->db->prepare('INSERT INTO administrator_student_audit_logs (actor_user_id, subject_user_id, action, summary, metadata, created_at) VALUES (:actor, :subject, :action, :summary, :metadata, NOW())');
        $statement->execute([
            'actor' => $actorId,
            'subject' => $studentId,
            'action' => $action,
            'summary' => substr($summary, 0, 255),
            'metadata' => $metadata === [] ? null : json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);
    }

    public function forStudent(int $studentId, int $limit = 50): array
    {
        $limit = max(1, min(100, $limit));
        $sql = 'SELECT l.id, l.action, l.summary, l.metadata, l.created_at,
            l.actor_user_id, a.name AS actor_name, a.email AS actor_email
            FROM administrator_student_audit_logs l
            LEFT JOIN users a ON a.id = l.actor_user_id
            WHERE l.subject_user_id = :student
            ORDER BY l.id DESC LIMIT ' . $limit;
        $statement = $this->db->prepare($sql);
        $statement->execute(['student' => $studentId]);
        $rows = [];
        foreach ($statement->fetchAll() as $row) {
            $row['id'] = (int) $row['id'];
            $row['actor_user_id'] = $row['actor_user_id'] === null ? null : (int) $row['actor_user_id'];
            $metadata = json_decode((string) ($row['metadata'] ?? ''), true);
            $row['metadata'] = is_array($metadata) ? $metadata : [];
            $rows[] = $row;
        }
        return $rows;
    }
}
