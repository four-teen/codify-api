<?php
declare(strict_types=1);

namespace Codify\Repositories;

use Codify\Core\HttpException;
use PDO;
use PDOException;

final class AcademicStructureRepository
{
    /** @var PDO */
    private $db;

    public function __construct(PDO $db) { $this->db = $db; }

    public function all(string $resource, string $search = '', ?int $parentId = null): array
    {
        list($sql, $alias, $parentField, $order) = $this->definition($resource);
        $where = [];
        $parameters = [];
        if ($search !== '') {
            $where[] = "({$alias}.code LIKE :search OR {$alias}.name LIKE :search)";
            $parameters['search'] = '%' . $search . '%';
        }
        if ($parentId !== null && $parentField !== null) {
            $where[] = "{$alias}.{$parentField} = :parent_id";
            $parameters['parent_id'] = $parentId;
        }
        if ($where !== []) $sql .= ' WHERE ' . implode(' AND ', $where);
        $statement = $this->db->prepare($sql . ' ORDER BY ' . $order);
        $statement->execute($parameters);
        return array_map([$this, 'cast'], $statement->fetchAll());
    }

    public function find(string $resource, int $id): ?array
    {
        list($sql, $alias) = $this->definition($resource);
        $statement = $this->db->prepare($sql . " WHERE {$alias}.id = :id LIMIT 1");
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();
        return $row ? $this->cast($row) : null;
    }

    public function create(string $resource, array $values): array
    {
        if ($resource === 'campuses') {
            $sql = 'INSERT INTO campuses (code, name, is_active, created_at, updated_at) VALUES (:code, :name, :is_active, NOW(), NOW())';
            $parameters = $this->baseParameters($values);
        } elseif ($resource === 'colleges') {
            $sql = 'INSERT INTO colleges (campus_id, code, name, is_active, created_at, updated_at) VALUES (:campus_id, :code, :name, :is_active, NOW(), NOW())';
            $parameters = $this->baseParameters($values) + ['campus_id' => $values['campus_id']];
        } elseif ($resource === 'programs') {
            $sql = 'INSERT INTO programs (college_id, code, name, is_active, created_at, updated_at) VALUES (:college_id, :code, :name, :is_active, NOW(), NOW())';
            $parameters = $this->baseParameters($values) + ['college_id' => $values['college_id']];
        } else {
            $sql = 'INSERT INTO subjects (program_id, code, name, units, is_active, created_at, updated_at) VALUES (:program_id, :code, :name, :units, :is_active, NOW(), NOW())';
            $parameters = $this->baseParameters($values) + ['program_id' => $values['program_id'], 'units' => $values['units']];
        }
        $this->db->prepare($sql)->execute($parameters);
        return $this->find($resource, (int) $this->db->lastInsertId()) ?: [];
    }

    public function update(string $resource, int $id, array $values): array
    {
        if ($resource === 'campuses') {
            $sql = 'UPDATE campuses SET code = :code, name = :name, is_active = :is_active, updated_at = NOW() WHERE id = :id';
            $parameters = $this->baseParameters($values) + ['id' => $id];
        } elseif ($resource === 'colleges') {
            $sql = 'UPDATE colleges SET campus_id = :campus_id, code = :code, name = :name, is_active = :is_active, updated_at = NOW() WHERE id = :id';
            $parameters = $this->baseParameters($values) + ['id' => $id, 'campus_id' => $values['campus_id']];
        } elseif ($resource === 'programs') {
            $sql = 'UPDATE programs SET college_id = :college_id, code = :code, name = :name, is_active = :is_active, updated_at = NOW() WHERE id = :id';
            $parameters = $this->baseParameters($values) + ['id' => $id, 'college_id' => $values['college_id']];
        } else {
            $sql = 'UPDATE subjects SET program_id = :program_id, code = :code, name = :name, units = :units, is_active = :is_active, updated_at = NOW() WHERE id = :id';
            $parameters = $this->baseParameters($values) + ['id' => $id, 'program_id' => $values['program_id'], 'units' => $values['units']];
        }
        $this->db->prepare($sql)->execute($parameters);
        return $this->find($resource, $id) ?: [];
    }

    public function delete(string $resource, int $id): void
    {
        $dependencies = [
            'campuses' => ['colleges', 'campus_id', 'colleges'],
            'colleges' => ['programs', 'college_id', 'programs'],
            'programs' => ['subjects', 'program_id', 'subjects'],
        ];
        if (isset($dependencies[$resource])) {
            list($table, $field, $label) = $dependencies[$resource];
            $statement = $this->db->prepare("SELECT COUNT(*) FROM {$table} WHERE {$field} = :id");
            $statement->execute(['id' => $id]);
            if ((int) $statement->fetchColumn() > 0) {
                throw new HttpException(422, "Remove or reassign the related {$label} before deleting this record.");
            }
        }
        try {
            $statement = $this->db->prepare('DELETE FROM ' . $resource . ' WHERE id = :id');
            $statement->execute(['id' => $id]);
        } catch (PDOException $exception) {
            throw new HttpException(422, 'This record is still used by another academic record.');
        }
    }

    public function assertParent(string $resource, int $parentId): void
    {
        $parents = ['colleges' => 'campuses', 'programs' => 'colleges', 'subjects' => 'programs'];
        if (!isset($parents[$resource])) return;
        $table = $parents[$resource];
        $statement = $this->db->prepare("SELECT id FROM {$table} WHERE id = :id LIMIT 1");
        $statement->execute(['id' => $parentId]);
        if (!$statement->fetchColumn()) throw new HttpException(422, 'The selected parent record does not exist.');
    }

    public function assertUnique(string $resource, string $code, string $name, ?int $parentId = null, ?int $ignore = null): void
    {
        $parentFields = ['colleges' => 'campus_id', 'programs' => 'college_id', 'subjects' => 'program_id'];
        $where = '(code = :code OR name = :name)';
        $parameters = ['code' => $code, 'name' => $name];
        if (isset($parentFields[$resource])) {
            $where .= ' AND ' . $parentFields[$resource] . ' = :parent_id';
            $parameters['parent_id'] = $parentId;
        }
        if ($ignore !== null) { $where .= ' AND id <> :ignore'; $parameters['ignore'] = $ignore; }
        $statement = $this->db->prepare("SELECT code, name FROM {$resource} WHERE {$where} LIMIT 1");
        $statement->execute($parameters);
        $row = $statement->fetch();
        if (!$row) return;
        $field = strcasecmp((string) $row['code'], $code) === 0 ? 'code' : 'name';
        throw new HttpException(422, 'The supplied data is invalid.', [$field => ['That ' . $field . ' is already used within the selected parent.']]);
    }

    private function definition(string $resource): array
    {
        if ($resource === 'campuses') return [
            'SELECT c.*, (SELECT COUNT(*) FROM colleges child WHERE child.campus_id = c.id) AS child_count FROM campuses c',
            'c', null, 'c.name, c.code'
        ];
        if ($resource === 'colleges') return [
            'SELECT c.*, ca.name AS campus_name, ca.code AS campus_code, (SELECT COUNT(*) FROM programs child WHERE child.college_id = c.id) AS child_count FROM colleges c INNER JOIN campuses ca ON ca.id = c.campus_id',
            'c', 'campus_id', 'ca.name, c.name, c.code'
        ];
        if ($resource === 'programs') return [
            'SELECT p.*, co.name AS college_name, co.code AS college_code, ca.id AS campus_id, ca.name AS campus_name, ca.code AS campus_code, (SELECT COUNT(*) FROM subjects child WHERE child.program_id = p.id) AS child_count FROM programs p INNER JOIN colleges co ON co.id = p.college_id INNER JOIN campuses ca ON ca.id = co.campus_id',
            'p', 'college_id', 'ca.name, co.name, p.name, p.code'
        ];
        if ($resource === 'subjects') return [
            'SELECT s.*, p.name AS program_name, p.code AS program_code, co.id AS college_id, co.name AS college_name, co.code AS college_code, ca.id AS campus_id, ca.name AS campus_name, ca.code AS campus_code, 0 AS child_count FROM subjects s INNER JOIN programs p ON p.id = s.program_id INNER JOIN colleges co ON co.id = p.college_id INNER JOIN campuses ca ON ca.id = co.campus_id',
            's', 'program_id', 'ca.name, co.name, p.name, s.name, s.code'
        ];
        throw new HttpException(404, 'Academic resource not found.');
    }

    private function baseParameters(array $values): array
    {
        return ['code' => $values['code'], 'name' => $values['name'], 'is_active' => $values['is_active'] ? 1 : 0];
    }

    private function cast(array $row): array
    {
        foreach (['id', 'campus_id', 'college_id', 'program_id', 'units', 'child_count'] as $field) {
            if (array_key_exists($field, $row) && $row[$field] !== null) $row[$field] = (int) $row[$field];
        }
        if (array_key_exists('is_active', $row)) $row['is_active'] = (bool) $row['is_active'];
        return $row;
    }
}
