<?php
declare(strict_types=1);

namespace Codify\Repositories;

use Codify\Core\HttpException;
use PDO;
use Throwable;

final class AdministratorStudentAuditRepository
{
    /** @var PDO */
    private $db;

    public function __construct(PDO $db) { $this->db = $db; }

    public function paginatedStudents(string $search, int $page, int $perPage): array
    {
        $where = 'u.role = \'student\'';
        $parameters = [];
        if ($search !== '') {
            $where .= ' AND (u.last_name LIKE :last_name OR u.email LIKE :email OR u.username LIKE :username OR u.name LIKE :name OR sp.student_number LIKE :student_number';
            $like = '%' . $search . '%';
            $parameters = ['last_name' => $like, 'email' => $like, 'username' => $like, 'name' => $like, 'student_number' => $like];
            if (ctype_digit($search)) {
                $where .= ' OR u.id = :student_id';
                $parameters['student_id'] = (int) $search;
            }
            $where .= ')';
        }

        $count = $this->db->prepare('SELECT COUNT(*) FROM users u LEFT JOIN student_profiles sp ON sp.user_id = u.id WHERE ' . $where);
        $count->execute($parameters);
        $total = (int) $count->fetchColumn();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $page), $lastPage);
        $offset = ($page - 1) * $perPage;

        $sql = 'SELECT u.id, u.first_name, u.last_name, u.name, u.username, u.email, u.is_active, u.created_at,
            sp.student_number, sp.course_label, sp.enrollment_status,
            p.code AS program_code, p.name AS program_name, f.name AS faculty_name,
            COALESCE(ds.total_devices, 0) AS total_devices,
            COALESCE(ds.recognized_devices, 0) AS recognized_devices,
            COALESCE(ds.new_devices, 0) AS new_devices,
            COALESCE(ds.reported_devices, 0) AS reported_devices,
            ds.last_device_seen_at,
            COALESCE(es.login_events, 0) AS login_events,
            es.latest_login_at
            FROM users u
            LEFT JOIN student_profiles sp ON sp.user_id = u.id
            LEFT JOIN programs p ON p.id = sp.program_id
            LEFT JOIN users f ON f.id = u.faculty_id
            LEFT JOIN (
                SELECT student_id, COUNT(*) AS total_devices,
                    SUM(status = \'recognized\') AS recognized_devices,
                    SUM(status = \'new\') AS new_devices,
                    SUM(status = \'reported\') AS reported_devices,
                    MAX(last_seen_at) AS last_device_seen_at
                FROM student_devices GROUP BY student_id
            ) ds ON ds.student_id = u.id
            LEFT JOIN (
                SELECT student_id, COUNT(*) AS login_events, MAX(occurred_at) AS latest_login_at
                FROM student_login_events GROUP BY student_id
            ) es ON es.student_id = u.id
            WHERE ' . $where . '
            ORDER BY COALESCE(u.last_name, u.name), COALESCE(u.first_name, u.name), u.id
            LIMIT ' . $perPage . ' OFFSET ' . $offset;
        $statement = $this->db->prepare($sql);
        $statement->execute($parameters);
        $data = array_map([$this, 'studentListPayload'], $statement->fetchAll());

        return [
            'current_page' => $page,
            'data' => $data,
            'from' => $total ? $offset + 1 : null,
            'last_page' => $lastPage,
            'per_page' => $perPage,
            'to' => $total ? min($offset + $perPage, $total) : null,
            'total' => $total,
        ];
    }

    public function student(int $studentId): array
    {
        $sql = 'SELECT u.id, u.faculty_id, u.first_name, u.last_name, u.name, u.username, u.email, u.is_active,
            u.must_change_password, u.created_at, u.updated_at,
            sp.student_number, sp.gender, sp.mobile_number, sp.course_label, sp.enrollment_status,
            p.id AS program_id, p.code AS program_code, p.name AS program_name,
            c.id AS college_id, c.code AS college_code, c.name AS college_name,
            ca.id AS campus_id, ca.code AS campus_code, ca.name AS campus_name,
            f.name AS faculty_name, f.email AS faculty_email
            FROM users u
            LEFT JOIN student_profiles sp ON sp.user_id = u.id
            LEFT JOIN programs p ON p.id = sp.program_id
            LEFT JOIN colleges c ON c.id = p.college_id
            LEFT JOIN campuses ca ON ca.id = c.campus_id
            LEFT JOIN users f ON f.id = u.faculty_id
            WHERE u.id = :student AND u.role = \'student\' LIMIT 1';
        $statement = $this->db->prepare($sql);
        $statement->execute(['student' => $studentId]);
        $row = $statement->fetch();
        if (!$row) throw new HttpException(404, 'Student account not found.');
        foreach (['id', 'faculty_id', 'program_id', 'college_id', 'campus_id'] as $key) {
            $row[$key] = $row[$key] === null ? null : (int) $row[$key];
        }
        $row['is_active'] = (bool) $row['is_active'];
        $row['must_change_password'] = (bool) $row['must_change_password'];
        return $row;
    }

    public function transaction(callable $callback)
    {
        $owns = !$this->db->inTransaction();
        if ($owns) $this->db->beginTransaction();
        try {
            $result = $callback();
            if ($owns) $this->db->commit();
            return $result;
        } catch (Throwable $exception) {
            if ($owns && $this->db->inTransaction()) $this->db->rollBack();
            throw $exception;
        }
    }

    private function studentListPayload(array $row): array
    {
        foreach (['id', 'total_devices', 'recognized_devices', 'new_devices', 'reported_devices', 'login_events'] as $key) {
            $row[$key] = (int) $row[$key];
        }
        $row['is_active'] = (bool) $row['is_active'];
        return $row;
    }
}
