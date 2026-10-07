<?php
declare(strict_types=1);

namespace Codify\Repositories;

use Codify\Core\HttpException;
use PDO;
use Throwable;

final class ProblemWorkRepository
{
    private $db;
    public function __construct(PDO $db) { $this->db = $db; }

    public function state(int $student, int $problem): array
    {
        $statement = $this->db->prepare('SELECT * FROM coding_problem_work WHERE student_id = ? AND problem_id = ?');
        $statement->execute([$student, $problem]);
        return $this->payload($statement->fetch() ?: ['status' => 'ready', 'close_count' => 0]);
    }

    public function act(int $student, int $problem, string $action, array $input): array
    {
        $owns = !$this->db->inTransaction();
        if ($owns) $this->db->beginTransaction();
        try {
            $this->db->prepare('INSERT IGNORE INTO coding_problem_work (student_id, problem_id) VALUES (?, ?)')->execute([$student, $problem]);
            $statement = $this->db->prepare('SELECT * FROM coding_problem_work WHERE student_id = ? AND problem_id = ? FOR UPDATE');
            $statement->execute([$student, $problem]);
            $row = $statement->fetch();
            $session = (string) ($input['session_token'] ?? '');
            if ($action === 'start') {
                // Reopening or taking over an unfinished attempt counts as one close.
                if ($row['status'] === 'active') $row = $this->close($row, 'reopened');
                if (!in_array($row['status'], ['locked', 'submitted'], true)) {
                    $row['status'] = 'active';
                    $row['session_token'] = bin2hex(random_bytes(32));
                    $row['last_seen_at'] = date('Y-m-d H:i:s');
                    (new ProblemRubricRepository($this->db))->snapshot($student, $problem);
                }
            } elseif ($action === 'close') {
                // Duplicate lifecycle events and old tabs cannot consume extra closes.
                if ($row['status'] === 'active' && $session !== '' && hash_equals((string) $row['session_token'], $session)) {
                    $reasons = ['clipboard', 'hidden', 'blur', 'pagehide', 'navigation', 'disconnected', 'refresh'];
                    $reason = (string) ($input['reason'] ?? 'navigation');
                    $row = $this->close($row, in_array($reason, $reasons, true) ? $reason : 'navigation');
                }
            } else {
                if ($row['status'] !== 'active' || $session === '' || !hash_equals((string) $row['session_token'], $session)) {
                    throw new HttpException(409, 'This coding attempt is no longer active. Reopen the problem to check its status.');
                }
                if (strtotime((string) $row['last_seen_at']) < time() - 90) {
                    $row = $this->close($row, 'disconnected');
                } elseif ($action === 'heartbeat') {
                    $row['last_seen_at'] = date('Y-m-d H:i:s');
                } elseif ($action === 'submit') {
                    $code = str_replace(["\r\n", "\r"], "\n", (string) ($input['code'] ?? ''));
                    $length = function_exists('mb_strlen') ? mb_strlen($code) : strlen($code);
                    if (trim($code) === '' || $length > 60000) throw new HttpException(422, 'Enter Python code of up to 60,000 characters.');
                    $row['status'] = 'submitted';
                    $row['submitted_code'] = $code;
                    $row['submitted_at'] = date('Y-m-d H:i:s');
                    $row['session_token'] = null;
                } else {
                    throw new HttpException(404, 'Coding action not found.');
                }
            }
            $save = $this->db->prepare('UPDATE coding_problem_work SET close_count = ?, status = ?, session_token = ?, last_seen_at = ?, last_close_reason = ?, submitted_code = ?, submitted_at = ? WHERE student_id = ? AND problem_id = ?');
            $save->execute([$row['close_count'], $row['status'], $row['session_token'], $row['last_seen_at'], $row['last_close_reason'], $row['submitted_code'], $row['submitted_at'], $student, $problem]);
            if ($owns) $this->db->commit();
            if ($action === 'submit' && $row['status'] === 'submitted') {
                try {
                    $owner = $this->db->prepare('SELECT faculty_id FROM coding_problems WHERE id = ?'); $owner->execute([$problem]);
                    (new ProblemRubricRepository($this->db))->generate((int) $owner->fetchColumn(), $problem, $student);
                } catch (Throwable $error) {
                    // A missing rubric or analyzer must never discard a submitted answer.
                }
            }
            $result = $this->payload($row);
            if ($action === 'start' && $row['status'] === 'active') $result['session_token'] = $row['session_token'];
            return $result;
        } catch (Throwable $error) {
            if ($owns && $this->db->inTransaction()) $this->db->rollBack();
            throw $error;
        }
    }

    private function close(array $row, string $reason): array
    {
        $row['close_count'] = min(3, (int) $row['close_count'] + 1);
        $row['status'] = $row['close_count'] >= 3 ? 'locked' : 'closed';
        $row['last_close_reason'] = $reason;
        $row['session_token'] = null;
        $this->db->prepare('INSERT INTO coding_problem_work_events (student_id, problem_id, reason, created_at) VALUES (?, ?, ?, NOW())')->execute([$row['student_id'], $row['problem_id'], $reason]);
        return $row;
    }

    private function payload(array $row): array
    {
        return ['status' => $row['status'], 'close_count' => (int) $row['close_count'], 'close_limit' => 3,
            'remaining_closes' => max(0, 3 - (int) $row['close_count']), 'last_close_reason' => $row['last_close_reason'] ?? null,
            'submitted_at' => $row['submitted_at'] ?? null, 'submitted_code' => $row['status'] === 'submitted' ? ($row['submitted_code'] ?? '') : ''];
    }

    public function responses(int $faculty, int $problem): array
    {
        $check = $this->db->prepare('SELECT id FROM coding_problems WHERE id = ? AND faculty_id = ?');
        $check->execute([$problem, $faculty]);
        if (!$check->fetch()) throw new HttpException(404, 'Python problem not found.');
        $query = $this->db->prepare("SELECT w.student_id, u.name AS student_name, w.status, w.close_count, w.last_close_reason, w.submitted_code, w.submitted_at FROM coding_problem_work w INNER JOIN users u ON u.id = w.student_id WHERE w.problem_id = ? ORDER BY w.submitted_at DESC, u.name");
        $query->execute([$problem]);
        $rows = $query->fetchAll();
        $rubrics = new ProblemRubricRepository($this->db);
        foreach ($rows as &$row) $row['evaluation'] = $rubrics->evaluation((int) $row['student_id'], $problem);
        unset($row);
        return $rows;
    }
}
