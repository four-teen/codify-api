<?php
declare(strict_types=1);

namespace Codify\Repositories;

use Codify\Core\HttpException;
use PDO;
use Throwable;

final class ProblemBankRepository
{
    /** @var PDO */
    private $db;

    public function __construct(PDO $db) { $this->db = $db; }

    public function rubricTemplates(int $faculty): array { return (new ProblemRubricRepository($this->db))->templates($faculty); }

    public function offerings(int $facultyId, string $academicYear, string $academicTerm): array
    {
        $statement = $this->db->prepare("SELECT fs.id, fs.subject_id, fs.section, fs.academic_year, fs.academic_term,
            s.code AS subject_code, s.name AS subject_name, p.code AS program_code, p.name AS program_name
            FROM faculty_subjects fs
            INNER JOIN subjects s ON s.id = fs.subject_id
            INNER JOIN programs p ON p.id = s.program_id
            WHERE fs.faculty_id = :faculty AND fs.academic_year = :academic_year AND fs.academic_term = :academic_term AND fs.is_active = 1
            ORDER BY p.code, s.code, fs.section");
        $statement->execute(['faculty' => $facultyId, 'academic_year' => $academicYear, 'academic_term' => $academicTerm]);
        return array_map([$this, 'offeringPayload'], $statement->fetchAll());
    }

    public function problems(int $facultyId, array $filters): array
    {
        $where = ['cp.faculty_id = :faculty'];
        $parameters = ['faculty' => $facultyId];
        if ($filters['search'] !== '') {
            $where[] = '(cp.code LIKE :search_code OR cp.title LIKE :search_title OR cp.tags LIKE :search_tags)';
            $term = '%' . $filters['search'] . '%';
            $parameters['search_code'] = $term; $parameters['search_title'] = $term; $parameters['search_tags'] = $term;
        }
        if ($filters['difficulty'] !== '') { $where[] = 'cp.difficulty = :difficulty'; $parameters['difficulty'] = $filters['difficulty']; }
        if ($filters['status'] === 'active') $where[] = 'cp.is_active = 1';
        if ($filters['status'] === 'inactive') $where[] = 'cp.is_active = 0';
        if ($filters['offering_id'] > 0) {
            $where[] = 'EXISTS (SELECT 1 FROM coding_problem_subjects filter_subject WHERE filter_subject.problem_id = cp.id AND filter_subject.faculty_subject_id = :offering)';
            $parameters['offering'] = $filters['offering_id'];
        }
        $sql = "SELECT cp.*,
            (SELECT COUNT(*) FROM coding_problem_work work WHERE work.problem_id = cp.id AND work.status = 'submitted') AS answered_count,
            (SELECT COUNT(*) FROM coding_problem_test_cases test_case WHERE test_case.problem_id = cp.id) AS test_cases_count,
            (SELECT COUNT(*) FROM coding_problem_test_cases sample_case WHERE sample_case.problem_id = cp.id AND sample_case.is_sample = 1) AS sample_cases_count,
            (SELECT GROUP_CONCAT(CONCAT(s.code, IF(fs.section = '', '', CONCAT(' / ', fs.section))) ORDER BY s.code, fs.section SEPARATOR ', ')
                FROM coding_problem_subjects cps
                INNER JOIN faculty_subjects fs ON fs.id = cps.faculty_subject_id
                INNER JOIN subjects s ON s.id = fs.subject_id
                WHERE cps.problem_id = cp.id) AS subject_labels
            FROM coding_problems cp WHERE " . implode(' AND ', $where) . ' ORDER BY cp.updated_at DESC, cp.code LIMIT 200';
        $statement = $this->db->prepare($sql); $statement->execute($parameters);
        return array_map([$this, 'problemListPayload'], $statement->fetchAll());
    }

    public function metrics(int $facultyId): array
    {
        $statement = $this->db->prepare("SELECT COUNT(*) AS total,
            SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) AS active,
            SUM(CASE WHEN difficulty = 'beginner' THEN 1 ELSE 0 END) AS beginner,
            SUM(CASE WHEN difficulty = 'intermediate' THEN 1 ELSE 0 END) AS intermediate,
            SUM(CASE WHEN difficulty = 'advanced' THEN 1 ELSE 0 END) AS advanced
            FROM coding_problems WHERE faculty_id = :faculty");
        $statement->execute(['faculty' => $facultyId]); $row = $statement->fetch() ?: [];
        return ['total' => (int) ($row['total'] ?? 0), 'active' => (int) ($row['active'] ?? 0), 'beginner' => (int) ($row['beginner'] ?? 0), 'intermediate' => (int) ($row['intermediate'] ?? 0), 'advanced' => (int) ($row['advanced'] ?? 0)];
    }

    public function problem(int $facultyId, int $problemId): array
    {
        $statement = $this->db->prepare('SELECT cp.*, (SELECT COUNT(*) FROM coding_problem_test_cases tc WHERE tc.problem_id = cp.id) AS test_cases_count FROM coding_problems cp WHERE cp.id = :id AND cp.faculty_id = :faculty LIMIT 1');
        $statement->execute(['id' => $problemId, 'faculty' => $facultyId]); $row = $statement->fetch();
        if (!$row) throw new HttpException(404, 'Python problem not found.');
        $problem = $this->problemPayload($row);
        $problem['subjects'] = $this->problemSubjects($problemId);
        $problem['faculty_subject_ids'] = array_map(static function (array $subject): int { return (int) $subject['id']; }, $problem['subjects']);
        $problem['test_cases'] = $this->testCases($problemId);
        $problem['expected_output'] = implode("\n\n", array_column($problem['test_cases'], 'expected_output'));
        $problem['show_expected_output'] = count($problem['test_cases']) > 0 && count(array_filter($problem['test_cases'], static function (array $case): bool { return !$case['is_sample']; })) === 0;
        $problem['rubric'] = (new ProblemRubricRepository($this->db))->problem($problemId);
        return $problem;
    }

    public function create(int $facultyId, array $data, array $subjectIds, array $testCases, string $academicYear, string $academicTerm): array
    {
        return $this->transaction(function () use ($facultyId, $data, $subjectIds, $testCases, $academicYear, $academicTerm) {
            $this->assertUniqueCode($facultyId, $data['code'], null);
            $this->assertOfferings($facultyId, $subjectIds, $academicYear, $academicTerm);
            $statement = $this->db->prepare("INSERT INTO coding_problems
                (faculty_id, code, title, language, difficulty, problem_statement, input_format, output_format, constraints_text, starter_code, reference_solution, solution_notes, tags, time_limit_ms, memory_limit_mb, is_active, created_at, updated_at)
                VALUES (:faculty, :code, :title, 'python', :difficulty, :problem_statement, :input_format, :output_format, :constraints_text, :starter_code, :reference_solution, :solution_notes, :tags, :time_limit_ms, :memory_limit_mb, :is_active, NOW(), NOW())");
            $statement->execute($this->parameters($facultyId, $data));
            $id = (int) $this->db->lastInsertId();
            $this->syncSubjects($id, $subjectIds); $this->replaceTestCases($id, $testCases);
            if (array_key_exists('rubric', $data)) (new ProblemRubricRepository($this->db))->attach($facultyId, $id, $data['rubric']);
            return $this->problem($facultyId, $id);
        });
    }

    public function update(int $facultyId, int $problemId, array $data, array $subjectIds, array $testCases, string $academicYear, string $academicTerm): array
    {
        return $this->transaction(function () use ($facultyId, $problemId, $data, $subjectIds, $testCases, $academicYear, $academicTerm) {
            $this->problem($facultyId, $problemId); $this->assertUniqueCode($facultyId, $data['code'], $problemId);
            $this->assertOfferings($facultyId, $subjectIds, $academicYear, $academicTerm);
            $parameters = $this->parameters($facultyId, $data); $parameters['id'] = $problemId;
            $statement = $this->db->prepare("UPDATE coding_problems SET code = :code, title = :title, difficulty = :difficulty,
                problem_statement = :problem_statement, input_format = :input_format, output_format = :output_format,
                constraints_text = :constraints_text, starter_code = :starter_code, reference_solution = :reference_solution,
                solution_notes = :solution_notes, tags = :tags, time_limit_ms = :time_limit_ms, memory_limit_mb = :memory_limit_mb,
                is_active = :is_active, updated_at = NOW() WHERE id = :id AND faculty_id = :faculty");
            $statement->execute($parameters);
            $this->syncSubjects($problemId, $subjectIds); $this->replaceTestCases($problemId, $testCases);
            if (array_key_exists('rubric', $data)) (new ProblemRubricRepository($this->db))->attach($facultyId, $problemId, $data['rubric']);
            return $this->problem($facultyId, $problemId);
        });
    }

    public function delete(int $facultyId, int $problemId): void
    {
        $this->problem($facultyId, $problemId);
        $statement = $this->db->prepare('DELETE FROM coding_problems WHERE id = :id AND faculty_id = :faculty');
        $statement->execute(['id' => $problemId, 'faculty' => $facultyId]);
    }

    private function problemSubjects(int $problemId): array
    {
        $statement = $this->db->prepare("SELECT fs.id, fs.subject_id, fs.section, fs.academic_year, fs.academic_term,
            s.code AS subject_code, s.name AS subject_name, p.code AS program_code, p.name AS program_name
            FROM coding_problem_subjects cps
            INNER JOIN faculty_subjects fs ON fs.id = cps.faculty_subject_id
            INNER JOIN subjects s ON s.id = fs.subject_id
            INNER JOIN programs p ON p.id = s.program_id
            WHERE cps.problem_id = :problem ORDER BY p.code, s.code, fs.section");
        $statement->execute(['problem' => $problemId]);
        return array_map([$this, 'offeringPayload'], $statement->fetchAll());
    }

    private function testCases(int $problemId): array
    {
        $statement = $this->db->prepare('SELECT * FROM coding_problem_test_cases WHERE problem_id = :problem ORDER BY position, id');
        $statement->execute(['problem' => $problemId]);
        return array_map(static function (array $row): array {
            return ['id' => (int) $row['id'], 'position' => (int) $row['position'], 'input_data' => $row['input_data'], 'expected_output' => $row['expected_output'], 'is_sample' => (bool) $row['is_sample'], 'points' => (int) $row['points']];
        }, $statement->fetchAll());
    }

    private function assertUniqueCode(int $facultyId, string $code, ?int $ignore): void
    {
        $sql = 'SELECT id FROM coding_problems WHERE faculty_id = :faculty AND code = :code'; $parameters = ['faculty' => $facultyId, 'code' => $code];
        if ($ignore !== null) { $sql .= ' AND id <> :ignore'; $parameters['ignore'] = $ignore; }
        $sql .= ' LIMIT 1'; $statement = $this->db->prepare($sql); $statement->execute($parameters);
        if ($statement->fetchColumn()) throw new HttpException(422, 'This problem code is already in your problem bank.', ['code' => ['Use a unique problem code.']]);
    }

    private function assertOfferings(int $facultyId, array $ids, string $academicYear, string $academicTerm): void
    {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $statement = $this->db->prepare("SELECT COUNT(*) FROM faculty_subjects WHERE faculty_id = ? AND academic_year = ? AND academic_term = ? AND is_active = 1 AND id IN ({$placeholders})");
        $statement->execute(array_merge([$facultyId, $academicYear, $academicTerm], $ids));
        if ((int) $statement->fetchColumn() !== count($ids)) throw new HttpException(403, 'One or more selected subjects are outside your current teaching load.');
    }

    private function syncSubjects(int $problemId, array $subjectIds): void
    {
        $this->db->prepare('DELETE FROM coding_problem_subjects WHERE problem_id = :problem')->execute(['problem' => $problemId]);
        $statement = $this->db->prepare('INSERT INTO coding_problem_subjects (problem_id, faculty_subject_id, created_at) VALUES (:problem, :subject, NOW())');
        foreach ($subjectIds as $subjectId) $statement->execute(['problem' => $problemId, 'subject' => $subjectId]);
    }

    private function replaceTestCases(int $problemId, array $testCases): void
    {
        $this->db->prepare('DELETE FROM coding_problem_test_cases WHERE problem_id = :problem')->execute(['problem' => $problemId]);
        $statement = $this->db->prepare('INSERT INTO coding_problem_test_cases (problem_id, position, input_data, expected_output, is_sample, points, created_at, updated_at) VALUES (:problem, :position, :input_data, :expected_output, :is_sample, :points, NOW(), NOW())');
        foreach ($testCases as $index => $testCase) {
            $statement->execute(['problem' => $problemId, 'position' => $index + 1, 'input_data' => $testCase['input_data'], 'expected_output' => $testCase['expected_output'], 'is_sample' => $testCase['is_sample'] ? 1 : 0, 'points' => $testCase['points']]);
        }
    }

    private function parameters(int $facultyId, array $data): array
    {
        return ['faculty' => $facultyId, 'code' => $data['code'], 'title' => $data['title'], 'difficulty' => $data['difficulty'], 'problem_statement' => $data['problem_statement'], 'input_format' => $data['input_format'], 'output_format' => $data['output_format'], 'constraints_text' => $data['constraints_text'], 'starter_code' => $data['starter_code'], 'reference_solution' => $data['reference_solution'], 'solution_notes' => $data['solution_notes'], 'tags' => $data['tags'], 'time_limit_ms' => $data['time_limit_ms'], 'memory_limit_mb' => $data['memory_limit_mb'], 'is_active' => $data['is_active'] ? 1 : 0];
    }

    private function offeringPayload(array $row): array
    {
        return ['id' => (int) $row['id'], 'subject_id' => (int) $row['subject_id'], 'section' => $row['section'], 'academic_year' => $row['academic_year'], 'academic_term' => $row['academic_term'], 'subject_code' => $row['subject_code'], 'subject_name' => $row['subject_name'], 'program_code' => $row['program_code'], 'program_name' => $row['program_name']];
    }

    private function problemListPayload(array $row): array
    {
        return ['answered_count' => (int) ($row['answered_count'] ?? 0), 'id' => (int) $row['id'], 'code' => $row['code'], 'title' => $row['title'], 'language' => $row['language'], 'difficulty' => $row['difficulty'], 'tags' => $row['tags'], 'time_limit_ms' => (int) $row['time_limit_ms'], 'memory_limit_mb' => (int) $row['memory_limit_mb'], 'is_active' => (bool) $row['is_active'], 'test_cases_count' => (int) $row['test_cases_count'], 'sample_cases_count' => (int) $row['sample_cases_count'], 'subject_labels' => $row['subject_labels'] ?: '', 'created_at' => $row['created_at'], 'updated_at' => $row['updated_at']];
    }

    private function problemPayload(array $row): array
    {
        $payload = $this->problemListPayload(array_merge($row, ['sample_cases_count' => $row['sample_cases_count'] ?? 0, 'subject_labels' => $row['subject_labels'] ?? '']));
        foreach (['problem_statement', 'input_format', 'output_format', 'constraints_text', 'starter_code', 'reference_solution', 'solution_notes'] as $field) $payload[$field] = $row[$field];
        return $payload;
    }

    private function transaction(callable $callback)
    {
        $owns = !$this->db->inTransaction(); if ($owns) $this->db->beginTransaction();
        try { $result = $callback(); if ($owns) $this->db->commit(); return $result; }
        catch (Throwable $exception) { if ($owns && $this->db->inTransaction()) $this->db->rollBack(); throw $exception; }
    }
}
