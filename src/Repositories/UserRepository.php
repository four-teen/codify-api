<?php
declare(strict_types=1);

namespace Codify\Repositories;

use Codify\Core\HttpException;
use PDO;
use Throwable;

final class UserRepository
{
    /** @var PDO */
    private $db;
    public function __construct(PDO $db) { $this->db = $db; }

    public function findByLogin(string $login): ?array
    {
        $statement = $this->db->prepare('SELECT * FROM users WHERE email = :email OR username = :username LIMIT 1');
        $statement->execute(['email' => strtolower($login), 'username' => $login]);
        $row = $statement->fetch();
        return $row ? $this->cast($row) : null;
    }

    public function findByUsername(string $username): ?array
    {
        $statement = $this->db->prepare('SELECT * FROM users WHERE username = :username LIMIT 1');
        $statement->execute(['username' => $username]);
        $row = $statement->fetch();
        return $row ? $this->cast($row) : null;
    }

    public function findStudentByNumber(string $studentNumber): ?array
    {
        $statement = $this->db->prepare("SELECT student.* FROM users student
            LEFT JOIN student_profiles profile ON profile.user_id = student.id
            WHERE student.role = 'student'
              AND (profile.student_number = :profile_number OR (profile.student_number IS NULL AND student.username = :legacy_username))
            ORDER BY CASE WHEN profile.student_number = :preferred_number THEN 0 ELSE 1 END, student.id
            LIMIT 1");
        $statement->execute([
            'profile_number' => $studentNumber,
            'legacy_username' => $studentNumber,
            'preferred_number' => $studentNumber,
        ]);
        $row = $statement->fetch();
        return $row ? $this->cast($row) : null;
    }

    public function findByEmail(string $email): ?array
    {
        $statement = $this->db->prepare('SELECT * FROM users WHERE email = :email LIMIT 1');
        $statement->execute(['email' => strtolower($email)]);
        $row = $statement->fetch();
        return $row ? $this->cast($row) : null;
    }

    public function find(int $id): ?array
    {
        $statement = $this->db->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();
        return $row ? $this->cast($row) : null;
    }

    public function payload(array $user, bool $withStudentCount = false): array
    {
        $statement = $this->db->prepare('SELECT p.code FROM permissions p INNER JOIN user_permissions up ON up.permission_id = p.id WHERE up.user_id = :id ORDER BY p.code');
        $statement->execute(['id' => $user['id']]);
        $payload = [
            'id' => (int) $user['id'], 'name' => $user['name'], 'username' => $user['username'], 'email' => $user['email'],
            'role' => $user['role'], 'is_active' => (bool) $user['is_active'], 'must_change_password' => (bool) $user['must_change_password'],
            'permissions' => $statement->fetchAll(PDO::FETCH_COLUMN),
        ];
        if (array_key_exists('first_name', $user)) $payload['first_name'] = $user['first_name'];
        if (array_key_exists('last_name', $user)) $payload['last_name'] = $user['last_name'];
        if (array_key_exists('faculty_id', $user)) $payload['faculty_id'] = $user['faculty_id'] === null ? null : (int) $user['faculty_id'];
        if (array_key_exists('created_at', $user)) $payload['created_at'] = $user['created_at'];
        if (array_key_exists('updated_at', $user)) $payload['updated_at'] = $user['updated_at'];
        if ($withStudentCount) $payload['students_count'] = $this->studentCount((int) $user['id']);
        return $payload;
    }

    public function paginatedManaged(string $search, string $role, int $page, int $perPage): array
    {
        $conditions = ["role IN ('administrator', 'faculty')"];
        $parameters = [];
        if ($role !== '') { $conditions[] = 'role = :role'; $parameters['role'] = $role; }
        if ($search !== '') {
            $conditions[] = '(name LIKE :search OR email LIKE :search OR username LIKE :search)';
            $parameters['search'] = '%' . $search . '%';
        }
        return $this->paginateUsers($conditions, $parameters, $page, $perPage, true);
    }

    public function paginatedStudents(int $facultyId, string $search, string $status, int $page, int $perPage): array
    {
        $conditions = ["role = 'student'", 'faculty_id = :faculty_id'];
        $parameters = ['faculty_id' => $facultyId];
        if ($status !== '') { $conditions[] = 'is_active = :active'; $parameters['active'] = $status === 'active' ? 1 : 0; }
        if ($search !== '') {
            $conditions[] = '(name LIKE :search OR email LIKE :search OR username LIKE :search)';
            $parameters['search'] = '%' . $search . '%';
        }
        return $this->paginateUsers($conditions, $parameters, $page, $perPage, false);
    }

    public function managed(int $id): array
    {
        $user = $this->find($id);
        if (!$user || !in_array($user['role'], ['administrator', 'faculty'], true)) throw new HttpException(404, 'User not found.');
        return $user;
    }

    public function ownedStudent(int $facultyId, int $studentId): array
    {
        $statement = $this->db->prepare("SELECT * FROM users WHERE id = :id AND faculty_id = :faculty AND role = 'student' LIMIT 1");
        $statement->execute(['id' => $studentId, 'faculty' => $facultyId]);
        $row = $statement->fetch();
        if (!$row) throw new HttpException(404, 'Student not found.');
        return $this->cast($row);
    }

    public function create(array $attributes, array $permissionCodes = []): array
    {
        $ownsTransaction = !$this->db->inTransaction();
        if ($ownsTransaction) $this->db->beginTransaction();
        try {
            $statement = $this->db->prepare('INSERT INTO users (faculty_id, first_name, last_name, name, username, email, password, role, is_active, must_change_password, created_at, updated_at) VALUES (:faculty_id, :first_name, :last_name, :name, :username, :email, :password, :role, :is_active, :must_change_password, NOW(), NOW())');
            $statement->execute([
                'faculty_id' => $attributes['faculty_id'] ?? null, 'first_name' => $attributes['first_name'] ?? null, 'last_name' => $attributes['last_name'] ?? null, 'name' => $attributes['name'], 'username' => $attributes['username'],
                'email' => $attributes['email'], 'password' => $attributes['password'], 'role' => $attributes['role'],
                'is_active' => $attributes['is_active'] ? 1 : 0, 'must_change_password' => $attributes['must_change_password'] ? 1 : 0,
            ]);
            $id = (int) $this->db->lastInsertId();
            $this->syncPermissions($id, $permissionCodes);
            if ($ownsTransaction) $this->db->commit();
            return $this->find($id) ?: [];
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->db->inTransaction()) $this->db->rollBack();
            throw $exception;
        }
    }

    public function update(int $id, array $attributes, ?array $permissionCodes = null): array
    {
        $ownsTransaction = !$this->db->inTransaction();
        if ($ownsTransaction) $this->db->beginTransaction();
        try {
            $sets = ['name = :name', 'username = :username', 'email = :email', 'role = :role', 'is_active = :is_active', 'updated_at = NOW()'];
            $parameters = ['id' => $id, 'name' => $attributes['name'], 'username' => $attributes['username'], 'email' => $attributes['email'], 'role' => $attributes['role'], 'is_active' => $attributes['is_active'] ? 1 : 0];
            if (array_key_exists('first_name', $attributes)) { $sets[] = 'first_name = :first_name'; $parameters['first_name'] = $attributes['first_name']; }
            if (array_key_exists('last_name', $attributes)) { $sets[] = 'last_name = :last_name'; $parameters['last_name'] = $attributes['last_name']; }
            if (!empty($attributes['password'])) { $sets[] = 'password = :password'; $sets[] = 'must_change_password = :must_change'; $parameters['password'] = $attributes['password']; $parameters['must_change'] = $attributes['must_change_password'] ? 1 : 0; }
            $statement = $this->db->prepare('UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = :id');
            $statement->execute($parameters);
            if ($permissionCodes !== null) $this->syncPermissions($id, $permissionCodes);
            if ($ownsTransaction) $this->db->commit();
            return $this->find($id) ?: [];
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->db->inTransaction()) $this->db->rollBack();
            throw $exception;
        }
    }

    public function updatePassword(int $id, string $hash): void
    {
        $this->db->prepare('UPDATE users SET password = :password, must_change_password = 0, updated_at = NOW() WHERE id = :id')->execute(['password' => $hash, 'id' => $id]);
    }

    public function repairStudentName(int $id, string $firstName, string $lastName): array
    {
        $statement = $this->db->prepare("UPDATE users SET first_name = :first_name, last_name = :last_name, name = :name, updated_at = NOW() WHERE id = :id AND role = 'student'");
        $statement->execute(['first_name' => $firstName, 'last_name' => $lastName, 'name' => trim($firstName . ' ' . $lastName), 'id' => $id]);
        return $this->find($id) ?: [];
    }

    public function setTemporaryPassword(int $id, string $hash): void
    {
        $this->db->prepare('UPDATE users SET password = :password, must_change_password = 1, updated_at = NOW() WHERE id = :id')->execute(['password' => $hash, 'id' => $id]);
    }

    public function rehashPassword(int $id, string $hash): void
    {
        $this->db->prepare('UPDATE users SET password = :password, updated_at = NOW() WHERE id = :id')->execute(['password' => $hash, 'id' => $id]);
    }

    public function delete(int $id): void { $this->db->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $id]); }
    public function studentCount(int $facultyId): int { $statement = $this->db->prepare("SELECT COUNT(*) FROM users WHERE faculty_id = :id AND role = 'student'"); $statement->execute(['id' => $facultyId]); return (int) $statement->fetchColumn(); }
    public function administratorCount(bool $activeOnly = false, ?int $exclude = null): int
    {
        $sql = "SELECT COUNT(*) FROM users WHERE role = 'administrator'" . ($activeOnly ? ' AND is_active = 1' : '') . ($exclude !== null ? ' AND id <> :exclude' : '');
        $statement = $this->db->prepare($sql); $statement->execute($exclude !== null ? ['exclude' => $exclude] : []); return (int) $statement->fetchColumn();
    }

    public function assertUnique(?string $username, string $email, ?int $ignore = null): void
    {
        $sql = 'SELECT id, username, email FROM users WHERE (email = :email' . ($username !== null ? ' OR username = :username' : '') . ')' . ($ignore !== null ? ' AND id <> :ignore' : '') . ' LIMIT 1';
        $parameters = ['email' => $email];
        if ($username !== null) $parameters['username'] = $username;
        if ($ignore !== null) $parameters['ignore'] = $ignore;
        $statement = $this->db->prepare($sql); $statement->execute($parameters); $row = $statement->fetch();
        if (!$row) return;
        $errors = [];
        if (strcasecmp((string) $row['email'], $email) === 0) $errors['email'][] = 'The email has already been taken.';
        if ($username !== null && strcasecmp((string) $row['username'], $username) === 0) $errors['username'][] = 'The username has already been taken.';
        throw new HttpException(422, 'The supplied data is invalid.', $errors);
    }

    public function validatePermissionCodes(array $codes): array
    {
        $codes = array_values(array_unique(array_filter(array_map('strval', $codes))));
        if ($codes === []) return [];
        $placeholders = implode(',', array_fill(0, count($codes), '?'));
        $statement = $this->db->prepare("SELECT code FROM permissions WHERE code IN ({$placeholders})"); $statement->execute($codes);
        $valid = $statement->fetchAll(PDO::FETCH_COLUMN);
        if (count($valid) !== count($codes)) throw new HttpException(422, 'One or more permissions are invalid.', ['permissions' => ['One or more permissions are invalid.']]);
        return $codes;
    }

    public function ensureInitialAdministrator(): void
    {
        $username = trim((string) env('CODIFY_INITIAL_ADMIN_USERNAME', ''));
        $email = strtolower(trim((string) env('CODIFY_INITIAL_ADMIN_EMAIL', '')));
        $password = (string) env('CODIFY_INITIAL_ADMIN_PASSWORD', '');
        if ($username === '' || $email === '' || $password === '') return;
        $existing = $this->findByLogin($email) ?: $this->findByLogin($username);
        if ($existing) return;
        $this->create([
            'faculty_id' => null, 'name' => env('CODIFY_INITIAL_ADMIN_NAME', 'Codify Administrator'), 'username' => $username,
            'email' => $email, 'password' => password_hash($password, PASSWORD_BCRYPT, ['cost' => max(10, min(14, (int) env('BCRYPT_ROUNDS', '12')))]),
            'role' => 'administrator', 'is_active' => true, 'must_change_password' => true,
        ]);
    }

    private function paginateUsers(array $conditions, array $parameters, int $page, int $perPage, bool $withStudentCount): array
    {
        $where = implode(' AND ', $conditions);
        $count = $this->db->prepare("SELECT COUNT(*) FROM users WHERE {$where}"); $count->execute($parameters); $total = (int) $count->fetchColumn();
        $lastPage = max(1, (int) ceil($total / $perPage)); $page = min(max(1, $page), $lastPage); $offset = ($page - 1) * $perPage;
        $statement = $this->db->prepare("SELECT * FROM users WHERE {$where} ORDER BY name LIMIT {$perPage} OFFSET {$offset}"); $statement->execute($parameters);
        $data = [];
        foreach ($statement->fetchAll() as $row) {
            $data[] = $this->payload($this->cast($row), $withStudentCount);
        }
        return ['current_page' => $page, 'data' => $data, 'from' => $total ? $offset + 1 : null, 'last_page' => $lastPage, 'per_page' => $perPage, 'to' => $total ? min($offset + $perPage, $total) : null, 'total' => $total];
    }

    private function syncPermissions(int $userId, array $codes): void
    {
        $this->db->prepare('DELETE FROM user_permissions WHERE user_id = :id')->execute(['id' => $userId]);
        if ($codes === []) return;
        $placeholders = implode(',', array_fill(0, count($codes), '?'));
        $statement = $this->db->prepare("SELECT id FROM permissions WHERE code IN ({$placeholders})"); $statement->execute($codes);
        $insert = $this->db->prepare('INSERT INTO user_permissions (user_id, permission_id) VALUES (:user, :permission)');
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $permissionId) $insert->execute(['user' => $userId, 'permission' => (int) $permissionId]);
    }

    private function cast(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['faculty_id'] = $row['faculty_id'] === null ? null : (int) $row['faculty_id'];
        $row['is_active'] = (bool) $row['is_active'];
        $row['must_change_password'] = (bool) $row['must_change_password'];
        return $row;
    }
}
