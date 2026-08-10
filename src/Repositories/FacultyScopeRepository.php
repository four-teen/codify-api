<?php
declare(strict_types=1);

namespace Codify\Repositories;

use Codify\Core\HttpException;
use PDO;
use Throwable;

final class FacultyScopeRepository
{
    /** @var PDO */
    private $db;
    public function __construct(PDO $db) { $this->db = $db; }

    public function paginatedFaculty(string $search, string $status, ?int $campusId, int $page, int $perPage): array
    {
        $where = ["u.role = 'faculty'"];
        $parameters = [];
        if ($search !== '') {
            $where[] = '(u.name LIKE :search_name OR u.email LIKE :search_email OR u.username LIKE :search_username)';
            $parameters['search_name'] = '%' . $search . '%'; $parameters['search_email'] = '%' . $search . '%'; $parameters['search_username'] = '%' . $search . '%';
        }
        if ($status !== '') { $where[] = 'u.is_active = :active'; $parameters['active'] = $status === 'active' ? 1 : 0; }
        if ($campusId !== null) { $where[] = 'fp.campus_id = :campus_id'; $parameters['campus_id'] = $campusId; }
        $clause = implode(' AND ', $where);
        $count = $this->db->prepare('SELECT COUNT(*) FROM users u LEFT JOIN faculty_profiles fp ON fp.user_id = u.id WHERE ' . $clause);
        $count->execute($parameters); $total = (int) $count->fetchColumn();
        $lastPage = max(1, (int) ceil($total / $perPage)); $page = min(max(1, $page), $lastPage); $offset = ($page - 1) * $perPage;
        $sql = "SELECT u.*, fp.campus_id, ca.code AS campus_code, ca.name AS campus_name,
                (SELECT COUNT(*) FROM users student WHERE student.faculty_id = u.id AND student.role = 'student') AS students_count
                FROM users u LEFT JOIN faculty_profiles fp ON fp.user_id = u.id LEFT JOIN campuses ca ON ca.id = fp.campus_id
                WHERE {$clause} ORDER BY u.name LIMIT {$perPage} OFFSET {$offset}";
        $statement = $this->db->prepare($sql); $statement->execute($parameters);
        $data = [];
        foreach ($statement->fetchAll() as $row) $data[] = $this->facultyPayload($row);
        return ['current_page' => $page, 'data' => $data, 'from' => $total ? $offset + 1 : null, 'last_page' => $lastPage, 'per_page' => $perPage, 'to' => $total ? min($offset + $perPage, $total) : null, 'total' => $total];
    }

    public function facultyPayload(array $user): array
    {
        return [
            'id' => (int) $user['id'], 'name' => $user['name'], 'username' => $user['username'], 'email' => $user['email'],
            'role' => 'faculty', 'is_active' => (bool) $user['is_active'], 'must_change_password' => (bool) $user['must_change_password'],
            'students_count' => isset($user['students_count']) ? (int) $user['students_count'] : $this->studentCount((int) $user['id']),
            'scope' => $this->scope((int) $user['id']), 'created_at' => $user['created_at'] ?? null, 'updated_at' => $user['updated_at'] ?? null,
        ];
    }

    public function scope(int $facultyId): array
    {
        $campusStatement = $this->db->prepare('SELECT ca.id, ca.code, ca.name, ca.is_active FROM faculty_profiles fp INNER JOIN campuses ca ON ca.id = fp.campus_id WHERE fp.user_id = :id LIMIT 1');
        $campusStatement->execute(['id' => $facultyId]); $campus = $campusStatement->fetch();
        if ($campus) { $campus['id'] = (int) $campus['id']; $campus['is_active'] = (bool) $campus['is_active']; }
        $colleges = $this->db->prepare('SELECT co.id, co.campus_id, co.code, co.name, co.is_active FROM faculty_colleges fc INNER JOIN colleges co ON co.id = fc.college_id WHERE fc.faculty_id = :id ORDER BY co.name');
        $colleges->execute(['id' => $facultyId]); $collegeRows = $colleges->fetchAll();
        foreach ($collegeRows as &$row) { $row['id'] = (int) $row['id']; $row['campus_id'] = (int) $row['campus_id']; $row['is_active'] = (bool) $row['is_active']; } unset($row);
        $programs = $this->db->prepare('SELECT p.id, p.college_id, p.code, p.name, p.is_active, co.code AS college_code, co.name AS college_name FROM faculty_programs fp INNER JOIN programs p ON p.id = fp.program_id INNER JOIN colleges co ON co.id = p.college_id WHERE fp.faculty_id = :id ORDER BY co.name, p.name');
        $programs->execute(['id' => $facultyId]); $programRows = $programs->fetchAll();
        foreach ($programRows as &$row) { $row['id'] = (int) $row['id']; $row['college_id'] = (int) $row['college_id']; $row['is_active'] = (bool) $row['is_active']; } unset($row);
        return ['campus' => $campus ?: null, 'colleges' => $collegeRows, 'programs' => $programRows];
    }

    public function validateAssignments($campusId, $collegeIds, $programIds): array
    {
        $campus = filter_var($campusId, FILTER_VALIDATE_INT);
        if ($campus === false || $campus < 1) throw new HttpException(422, 'The supplied data is invalid.', ['campus_id' => ['Select one valid campus.']]);
        $colleges = $this->normalizeIds($collegeIds, 'college_ids');
        $programs = $this->normalizeIds($programIds, 'program_ids');
        if ($colleges === []) throw new HttpException(422, 'The supplied data is invalid.', ['college_ids' => ['Select at least one college.']]);
        if ($programs === []) throw new HttpException(422, 'The supplied data is invalid.', ['program_ids' => ['Select at least one program.']]);
        $statement = $this->db->prepare('SELECT id FROM campuses WHERE id = :id AND is_active = 1 LIMIT 1');
        $statement->execute(['id' => (int) $campus]);
        if (!$statement->fetchColumn()) throw new HttpException(422, 'The supplied data is invalid.', ['campus_id' => ['The selected campus is unavailable.']]);
        $collegePlaceholders = implode(',', array_fill(0, count($colleges), '?'));
        $statement = $this->db->prepare("SELECT id FROM colleges WHERE id IN ({$collegePlaceholders}) AND campus_id = ? AND is_active = 1");
        $statement->execute(array_merge($colleges, [(int) $campus]));
        if (count($statement->fetchAll(PDO::FETCH_COLUMN)) !== count($colleges)) throw new HttpException(422, 'The supplied data is invalid.', ['college_ids' => ['Every selected college must be active and belong to the selected campus.']]);
        $programPlaceholders = implode(',', array_fill(0, count($programs), '?'));
        $selectedCollegePlaceholders = implode(',', array_fill(0, count($colleges), '?'));
        $statement = $this->db->prepare("SELECT id FROM programs WHERE id IN ({$programPlaceholders}) AND college_id IN ({$selectedCollegePlaceholders}) AND is_active = 1");
        $statement->execute(array_merge($programs, $colleges));
        if (count($statement->fetchAll(PDO::FETCH_COLUMN)) !== count($programs)) throw new HttpException(422, 'The supplied data is invalid.', ['program_ids' => ['Every selected program must be active and belong to a selected college.']]);
        return ['campus_id' => (int) $campus, 'college_ids' => $colleges, 'program_ids' => $programs];
    }

    public function syncFaculty(int $facultyId, int $campusId, array $collegeIds, array $programIds): void
    {
        $this->db->prepare('DELETE FROM faculty_programs WHERE faculty_id = :id')->execute(['id' => $facultyId]);
        $this->db->prepare('DELETE FROM faculty_colleges WHERE faculty_id = :id')->execute(['id' => $facultyId]);
        $this->db->prepare('INSERT INTO faculty_profiles (user_id, campus_id, created_at, updated_at) VALUES (:user, :campus, NOW(), NOW()) ON DUPLICATE KEY UPDATE campus_id = VALUES(campus_id), updated_at = NOW()')->execute(['user' => $facultyId, 'campus' => $campusId]);
        $college = $this->db->prepare('INSERT INTO faculty_colleges (faculty_id, college_id, created_at) VALUES (:faculty, :college, NOW())');
        foreach ($collegeIds as $collegeId) $college->execute(['faculty' => $facultyId, 'college' => $collegeId]);
        $program = $this->db->prepare('INSERT INTO faculty_programs (faculty_id, program_id, created_at) VALUES (:faculty, :program, NOW())');
        foreach ($programIds as $programId) $program->execute(['faculty' => $facultyId, 'program' => $programId]);
    }

    public function transaction(callable $callback)
    {
        $owns = !$this->db->inTransaction();
        if ($owns) $this->db->beginTransaction();
        try { $result = $callback(); if ($owns) $this->db->commit(); return $result; }
        catch (Throwable $exception) { if ($owns && $this->db->inTransaction()) $this->db->rollBack(); throw $exception; }
    }

    public function assertProgramAccess(int $facultyId, int $programId): void
    {
        $statement = $this->db->prepare('SELECT p.id FROM faculty_programs fp INNER JOIN programs p ON p.id = fp.program_id INNER JOIN faculty_profiles profile ON profile.user_id = fp.faculty_id INNER JOIN colleges co ON co.id = p.college_id WHERE fp.faculty_id = :faculty AND p.id = :program AND co.campus_id = profile.campus_id AND p.is_active = 1 LIMIT 1');
        $statement->execute(['faculty' => $facultyId, 'program' => $programId]);
        if (!$statement->fetchColumn()) throw new HttpException(403, 'The selected program is outside your assigned academic scope.');
    }

    public function assertStudentsWithinPrograms(int $facultyId, array $programIds): void
    {
        $placeholders = implode(',', array_fill(0, count($programIds), '?'));
        $statement = $this->db->prepare("SELECT COUNT(*) FROM users u INNER JOIN student_profiles sp ON sp.user_id = u.id WHERE u.faculty_id = ? AND u.role = 'student' AND sp.program_id NOT IN ({$placeholders})");
        $statement->execute(array_merge([$facultyId], $programIds));
        if ((int) $statement->fetchColumn() > 0) throw new HttpException(422, 'Some students use programs you are trying to remove. Reassign those students before changing the faculty scope.');
    }

    public function syncStudentProgram(int $studentId, int $programId): void
    {
        $this->db->prepare('INSERT INTO student_profiles (user_id, program_id, created_at, updated_at) VALUES (:user, :program, NOW(), NOW()) ON DUPLICATE KEY UPDATE program_id = VALUES(program_id), updated_at = NOW()')->execute(['user' => $studentId, 'program' => $programId]);
    }

    public function paginatedStudents(int $facultyId, string $search, string $status, int $page, int $perPage): array
    {
        $where = ["u.role = 'student'", 'u.faculty_id = :owner', '(sp.program_id IS NULL OR assigned.faculty_id IS NOT NULL)'];
        $parameters = ['owner' => $facultyId, 'scope_join' => $facultyId];
        if ($status !== '') { $where[] = 'u.is_active = :active'; $parameters['active'] = $status === 'active' ? 1 : 0; }
        if ($search !== '') {
            $where[] = '(u.name LIKE :student_name OR u.email LIKE :student_email OR u.username LIKE :student_username OR p.code LIKE :program_code)';
            $parameters['student_name'] = '%' . $search . '%'; $parameters['student_email'] = '%' . $search . '%'; $parameters['student_username'] = '%' . $search . '%'; $parameters['program_code'] = '%' . $search . '%';
        }
        $from = ' FROM users u LEFT JOIN student_profiles sp ON sp.user_id = u.id LEFT JOIN faculty_programs assigned ON assigned.program_id = sp.program_id AND assigned.faculty_id = :scope_join LEFT JOIN programs p ON p.id = sp.program_id LEFT JOIN colleges co ON co.id = p.college_id LEFT JOIN campuses ca ON ca.id = co.campus_id ';
        $clause = implode(' AND ', $where);
        $count = $this->db->prepare('SELECT COUNT(*)' . $from . 'WHERE ' . $clause); $count->execute($parameters); $total = (int) $count->fetchColumn();
        $lastPage = max(1, (int) ceil($total / $perPage)); $page = min(max(1, $page), $lastPage); $offset = ($page - 1) * $perPage;
        $statement = $this->db->prepare('SELECT u.*, sp.program_id, p.code AS program_code, p.name AS program_name, co.id AS college_id, co.code AS college_code, co.name AS college_name, ca.id AS campus_id, ca.code AS campus_code, ca.name AS campus_name' . $from . 'WHERE ' . $clause . " ORDER BY u.name LIMIT {$perPage} OFFSET {$offset}");
        $statement->execute($parameters); $data = array_map([$this, 'studentPayload'], $statement->fetchAll());
        return ['current_page' => $page, 'data' => $data, 'from' => $total ? $offset + 1 : null, 'last_page' => $lastPage, 'per_page' => $perPage, 'to' => $total ? min($offset + $perPage, $total) : null, 'total' => $total];
    }

    public function ownedStudent(int $facultyId, int $studentId): array
    {
        $statement = $this->db->prepare("SELECT u.*, sp.program_id, p.code AS program_code, p.name AS program_name, co.id AS college_id, co.code AS college_code, co.name AS college_name, ca.id AS campus_id, ca.code AS campus_code, ca.name AS campus_name FROM users u LEFT JOIN student_profiles sp ON sp.user_id = u.id LEFT JOIN faculty_programs assigned ON assigned.program_id = sp.program_id AND assigned.faculty_id = :scope_join LEFT JOIN programs p ON p.id = sp.program_id LEFT JOIN colleges co ON co.id = p.college_id LEFT JOIN campuses ca ON ca.id = co.campus_id WHERE u.id = :student AND u.faculty_id = :owner AND (sp.program_id IS NULL OR assigned.faculty_id IS NOT NULL) AND u.role = 'student' LIMIT 1");
        $statement->execute(['student' => $studentId, 'owner' => $facultyId, 'scope_join' => $facultyId]); $row = $statement->fetch();
        if (!$row) throw new HttpException(404, 'Student not found within your assigned academic scope.');
        return $this->studentPayload($row);
    }

    public function studentMetrics(int $facultyId): array
    {
        $statement = $this->db->prepare("SELECT COUNT(*) AS total, SUM(CASE WHEN u.is_active = 1 THEN 1 ELSE 0 END) AS active FROM users u INNER JOIN student_profiles sp ON sp.user_id = u.id INNER JOIN faculty_programs assigned ON assigned.program_id = sp.program_id WHERE u.faculty_id = :owner AND assigned.faculty_id = :scope AND u.role = 'student'");
        $statement->execute(['owner' => $facultyId, 'scope' => $facultyId]); $row = $statement->fetch();
        $subjectStatement = $this->db->prepare('SELECT COUNT(*) AS total, SUM(CASE WHEN fs.is_active = 1 THEN 1 ELSE 0 END) AS active FROM faculty_subjects fs WHERE fs.faculty_id = :faculty');
        $subjectStatement->execute(['faculty' => $facultyId]); $subjectRow = $subjectStatement->fetch();
        return [
            'students_total' => (int) ($row['total'] ?? 0),
            'students_active' => (int) ($row['active'] ?? 0),
            'subjects_total' => (int) ($subjectRow['total'] ?? 0),
            'subjects_active' => (int) ($subjectRow['active'] ?? 0),
        ];
    }

    public function paginatedSubjects(int $facultyId, string $search, string $status, ?int $programId, int $page, int $perPage): array
    {
        $from = ' FROM subjects s INNER JOIN programs p ON p.id = s.program_id INNER JOIN colleges co ON co.id = p.college_id INNER JOIN campuses ca ON ca.id = co.campus_id INNER JOIN faculty_programs assigned ON assigned.program_id = p.id INNER JOIN faculty_profiles profile ON profile.user_id = assigned.faculty_id ';
        $where = ['assigned.faculty_id = :faculty', 'co.campus_id = profile.campus_id'];
        $parameters = ['faculty' => $facultyId];
        if ($search !== '') {
            $where[] = '(s.code LIKE :subject_code OR s.name LIKE :subject_name OR p.code LIKE :program_code OR p.name LIKE :program_name)';
            $parameters['subject_code'] = '%' . $search . '%';
            $parameters['subject_name'] = '%' . $search . '%';
            $parameters['program_code'] = '%' . $search . '%';
            $parameters['program_name'] = '%' . $search . '%';
        }
        if ($status !== '') {
            $where[] = 's.is_active = :active';
            $parameters['active'] = $status === 'active' ? 1 : 0;
        }
        if ($programId !== null) {
            $where[] = 'p.id = :program_id';
            $parameters['program_id'] = $programId;
        }

        $clause = implode(' AND ', $where);
        $count = $this->db->prepare('SELECT COUNT(DISTINCT s.id)' . $from . 'WHERE ' . $clause);
        $count->execute($parameters);
        $total = (int) $count->fetchColumn();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $page), $lastPage);
        $offset = ($page - 1) * $perPage;

        $statement = $this->db->prepare('SELECT s.id, s.program_id, s.code, s.name, s.units, s.is_active, s.created_at, s.updated_at, p.code AS program_code, p.name AS program_name, co.id AS college_id, co.code AS college_code, co.name AS college_name, ca.id AS campus_id, ca.code AS campus_code, ca.name AS campus_name' . $from . 'WHERE ' . $clause . " ORDER BY co.name, p.name, s.code LIMIT {$perPage} OFFSET {$offset}");
        $statement->execute($parameters);
        $data = [];
        foreach ($statement->fetchAll() as $row) {
            $row['id'] = (int) $row['id'];
            $row['program_id'] = (int) $row['program_id'];
            $row['college_id'] = (int) $row['college_id'];
            $row['campus_id'] = (int) $row['campus_id'];
            $row['units'] = (int) $row['units'];
            $row['is_active'] = (bool) $row['is_active'];
            $data[] = $row;
        }
        return ['current_page' => $page, 'data' => $data, 'from' => $total ? $offset + 1 : null, 'last_page' => $lastPage, 'per_page' => $perPage, 'to' => $total ? min($offset + $perPage, $total) : null, 'total' => $total];
    }

    private function studentPayload(array $row): array
    {
        return [
            'id' => (int) $row['id'], 'faculty_id' => (int) $row['faculty_id'], 'name' => $row['name'], 'username' => $row['username'], 'email' => $row['email'],
            'first_name' => $row['first_name'] ?? null, 'last_name' => $row['last_name'] ?? null,
            'role' => 'student', 'is_active' => (bool) $row['is_active'], 'must_change_password' => (bool) $row['must_change_password'],
            'program_id' => $row['program_id'] === null ? null : (int) $row['program_id'], 'program_code' => $row['program_code'], 'program_name' => $row['program_name'],
            'college_id' => $row['college_id'] === null ? null : (int) $row['college_id'], 'college_code' => $row['college_code'], 'college_name' => $row['college_name'],
            'campus_id' => $row['campus_id'] === null ? null : (int) $row['campus_id'], 'campus_code' => $row['campus_code'], 'campus_name' => $row['campus_name'],
            'created_at' => $row['created_at'] ?? null, 'updated_at' => $row['updated_at'] ?? null,
        ];
    }

    private function normalizeIds($values, string $field): array
    {
        if (!is_array($values)) throw new HttpException(422, 'The supplied data is invalid.', [$field => ['This field must be an array of IDs.']]);
        $ids = [];
        foreach ($values as $value) {
            $id = filter_var($value, FILTER_VALIDATE_INT);
            if ($id === false || $id < 1) throw new HttpException(422, 'The supplied data is invalid.', [$field => ['Every selected value must be a valid ID.']]);
            $ids[(int) $id] = (int) $id;
        }
        return array_values($ids);
    }

    private function studentCount(int $facultyId): int
    {
        $statement = $this->db->prepare("SELECT COUNT(*) FROM users WHERE faculty_id = :id AND role = 'student'"); $statement->execute(['id' => $facultyId]); return (int) $statement->fetchColumn();
    }
}
