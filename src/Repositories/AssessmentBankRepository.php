<?php
declare(strict_types=1);

namespace Codify\Repositories;

use Codify\Core\HttpException;
use PDO;
use Throwable;

final class AssessmentBankRepository
{
    /** @var PDO */
    private $db;

    public function __construct(PDO $db) { $this->db = $db; }

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

    public function banks(int $facultyId, array $filters): array
    {
        $where = ['bank.faculty_id = :faculty']; $parameters = ['faculty' => $facultyId];
        if ($filters['search'] !== '') {
            $where[] = '(bank.code LIKE :search_code OR bank.title LIKE :search_title OR bank.description LIKE :search_description)';
            $term = '%' . $filters['search'] . '%';
            $parameters['search_code'] = $term; $parameters['search_title'] = $term; $parameters['search_description'] = $term;
        }
        if ($filters['bank_type'] !== '') { $where[] = 'bank.bank_type = :bank_type'; $parameters['bank_type'] = $filters['bank_type']; }
        if ($filters['status'] === 'active') $where[] = 'bank.is_active = 1';
        if ($filters['status'] === 'draft') $where[] = 'bank.is_active = 0';
        if ($filters['offering_id'] > 0) {
            $where[] = 'EXISTS (SELECT 1 FROM assessment_bank_subjects filter_subject WHERE filter_subject.assessment_bank_id = bank.id AND filter_subject.faculty_subject_id = :offering)';
            $parameters['offering'] = $filters['offering_id'];
        }
        $sql = "SELECT bank.*,
            (SELECT COUNT(*) FROM assessment_bank_questions question WHERE question.assessment_bank_id = bank.id) AS questions_count,
            (SELECT COALESCE(SUM(question.points), 0) FROM assessment_bank_questions question WHERE question.assessment_bank_id = bank.id) AS total_points,
            (SELECT GROUP_CONCAT(CONCAT(s.code, IF(fs.section = '', '', CONCAT(' / ', fs.section))) ORDER BY s.code, fs.section SEPARATOR ', ')
                FROM assessment_bank_subjects abs
                INNER JOIN faculty_subjects fs ON fs.id = abs.faculty_subject_id
                INNER JOIN subjects s ON s.id = fs.subject_id
                WHERE abs.assessment_bank_id = bank.id) AS subject_labels
            FROM assessment_banks bank WHERE " . implode(' AND ', $where) . ' ORDER BY bank.updated_at DESC, bank.code LIMIT 200';
        $statement = $this->db->prepare($sql); $statement->execute($parameters);
        return array_map([$this, 'bankListPayload'], $statement->fetchAll());
    }

    public function metrics(int $facultyId): array
    {
        $statement = $this->db->prepare("SELECT COUNT(*) AS total,
            SUM(CASE WHEN bank_type = 'quiz' THEN 1 ELSE 0 END) AS quizzes,
            SUM(CASE WHEN bank_type = 'exam' THEN 1 ELSE 0 END) AS exams,
            SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) AS active,
            (SELECT COUNT(*) FROM assessment_bank_questions question INNER JOIN assessment_banks owner ON owner.id = question.assessment_bank_id WHERE owner.faculty_id = :question_faculty) AS questions
            FROM assessment_banks WHERE faculty_id = :faculty");
        $statement->execute(['faculty' => $facultyId, 'question_faculty' => $facultyId]); $row = $statement->fetch() ?: [];
        return ['total' => (int) ($row['total'] ?? 0), 'quizzes' => (int) ($row['quizzes'] ?? 0), 'exams' => (int) ($row['exams'] ?? 0), 'active' => (int) ($row['active'] ?? 0), 'questions' => (int) ($row['questions'] ?? 0)];
    }

    public function bank(int $facultyId, int $bankId): array
    {
        $statement = $this->db->prepare("SELECT bank.*,
            (SELECT COUNT(*) FROM assessment_bank_questions question WHERE question.assessment_bank_id = bank.id) AS questions_count,
            (SELECT COALESCE(SUM(question.points), 0) FROM assessment_bank_questions question WHERE question.assessment_bank_id = bank.id) AS total_points
            FROM assessment_banks bank WHERE bank.id = :id AND bank.faculty_id = :faculty LIMIT 1");
        $statement->execute(['id' => $bankId, 'faculty' => $facultyId]); $row = $statement->fetch();
        if (!$row) throw new HttpException(404, 'Quiz or exam bank not found.');
        $bank = $this->bankPayload($row);
        $bank['subjects'] = $this->bankSubjects($bankId);
        $bank['faculty_subject_ids'] = array_map(static function (array $subject): int { return (int) $subject['id']; }, $bank['subjects']);
        $bank['questions'] = $this->questions($bankId);
        return $bank;
    }

    public function create(int $facultyId, array $data, array $subjectIds, array $questions, string $academicYear, string $academicTerm): array
    {
        return $this->transaction(function () use ($facultyId, $data, $subjectIds, $questions, $academicYear, $academicTerm) {
            $this->assertUniqueCode($facultyId, $data['code'], null);
            $this->assertOfferings($facultyId, $subjectIds, $academicYear, $academicTerm);
            $statement = $this->db->prepare("INSERT INTO assessment_banks
                (faculty_id, code, title, bank_type, description, instructions, is_active, created_at, updated_at)
                VALUES (:faculty, :code, :title, :bank_type, :description, :instructions, :is_active, NOW(), NOW())");
            $statement->execute($this->parameters($facultyId, $data)); $id = (int) $this->db->lastInsertId();
            $this->syncSubjects($id, $subjectIds); $this->replaceQuestions($id, $questions);
            return $this->bank($facultyId, $id);
        });
    }

    public function update(int $facultyId, int $bankId, array $data, array $subjectIds, array $questions, string $academicYear, string $academicTerm): array
    {
        return $this->transaction(function () use ($facultyId, $bankId, $data, $subjectIds, $questions, $academicYear, $academicTerm) {
            $this->bank($facultyId, $bankId); $this->assertUniqueCode($facultyId, $data['code'], $bankId);
            $this->assertOfferings($facultyId, $subjectIds, $academicYear, $academicTerm);
            $parameters = $this->parameters($facultyId, $data); $parameters['id'] = $bankId;
            $statement = $this->db->prepare("UPDATE assessment_banks SET code = :code, title = :title, bank_type = :bank_type,
                description = :description, instructions = :instructions, is_active = :is_active, updated_at = NOW()
                WHERE id = :id AND faculty_id = :faculty");
            $statement->execute($parameters);
            $this->syncSubjects($bankId, $subjectIds); $this->replaceQuestions($bankId, $questions);
            return $this->bank($facultyId, $bankId);
        });
    }

    public function delete(int $facultyId, int $bankId): void
    {
        $this->bank($facultyId, $bankId);
        $statement = $this->db->prepare('DELETE FROM assessment_banks WHERE id = :id AND faculty_id = :faculty');
        $statement->execute(['id' => $bankId, 'faculty' => $facultyId]);
    }

    private function bankSubjects(int $bankId): array
    {
        $statement = $this->db->prepare("SELECT fs.id, fs.subject_id, fs.section, fs.academic_year, fs.academic_term,
            s.code AS subject_code, s.name AS subject_name, p.code AS program_code, p.name AS program_name
            FROM assessment_bank_subjects abs
            INNER JOIN faculty_subjects fs ON fs.id = abs.faculty_subject_id
            INNER JOIN subjects s ON s.id = fs.subject_id
            INNER JOIN programs p ON p.id = s.program_id
            WHERE abs.assessment_bank_id = :bank ORDER BY p.code, s.code, fs.section");
        $statement->execute(['bank' => $bankId]);
        return array_map([$this, 'offeringPayload'], $statement->fetchAll());
    }

    private function questions(int $bankId): array
    {
        $statement = $this->db->prepare('SELECT * FROM assessment_bank_questions WHERE assessment_bank_id = :bank ORDER BY position, id');
        $statement->execute(['bank' => $bankId]);
        return array_map([$this, 'questionPayload'], $statement->fetchAll());
    }

    private function assertUniqueCode(int $facultyId, string $code, ?int $ignore): void
    {
        $sql = 'SELECT id FROM assessment_banks WHERE faculty_id = :faculty AND code = :code'; $parameters = ['faculty' => $facultyId, 'code' => $code];
        if ($ignore !== null) { $sql .= ' AND id <> :ignore'; $parameters['ignore'] = $ignore; }
        $sql .= ' LIMIT 1'; $statement = $this->db->prepare($sql); $statement->execute($parameters);
        if ($statement->fetchColumn()) throw new HttpException(422, 'This bank code is already in use.', ['code' => ['Use a unique quiz or exam bank code.']]);
    }

    private function assertOfferings(int $facultyId, array $ids, string $academicYear, string $academicTerm): void
    {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $statement = $this->db->prepare("SELECT COUNT(*) FROM faculty_subjects WHERE faculty_id = ? AND academic_year = ? AND academic_term = ? AND is_active = 1 AND id IN ({$placeholders})");
        $statement->execute(array_merge([$facultyId, $academicYear, $academicTerm], $ids));
        if ((int) $statement->fetchColumn() !== count($ids)) throw new HttpException(403, 'One or more selected subjects are outside your current teaching load.');
    }

    private function syncSubjects(int $bankId, array $subjectIds): void
    {
        $this->db->prepare('DELETE FROM assessment_bank_subjects WHERE assessment_bank_id = :bank')->execute(['bank' => $bankId]);
        $statement = $this->db->prepare('INSERT INTO assessment_bank_subjects (assessment_bank_id, faculty_subject_id, created_at) VALUES (:bank, :subject, NOW())');
        foreach ($subjectIds as $subjectId) $statement->execute(['bank' => $bankId, 'subject' => $subjectId]);
    }

    private function replaceQuestions(int $bankId, array $questions): void
    {
        $this->db->prepare('DELETE FROM assessment_bank_questions WHERE assessment_bank_id = :bank')->execute(['bank' => $bankId]);
        $statement = $this->db->prepare("INSERT INTO assessment_bank_questions
            (assessment_bank_id, position, question_type, question_text, options_json, correct_answers_json, accepted_answers_json, case_sensitive, points, is_required, answer_explanation, created_at, updated_at)
            VALUES (:bank, :position, :question_type, :question_text, :options_json, :correct_answers_json, :accepted_answers_json, :case_sensitive, :points, :is_required, :answer_explanation, NOW(), NOW())");
        foreach ($questions as $index => $question) {
            $statement->execute([
                'bank' => $bankId, 'position' => $index + 1, 'question_type' => $question['question_type'], 'question_text' => $question['question_text'],
                'options_json' => $this->encoded($question['options']), 'correct_answers_json' => $this->encoded($question['correct_answers']),
                'accepted_answers_json' => $this->encoded($question['accepted_answers']), 'case_sensitive' => $question['case_sensitive'] ? 1 : 0,
                'points' => $question['points'], 'is_required' => $question['is_required'] ? 1 : 0, 'answer_explanation' => $question['answer_explanation'],
            ]);
        }
    }

    private function parameters(int $facultyId, array $data): array
    {
        return ['faculty' => $facultyId, 'code' => $data['code'], 'title' => $data['title'], 'bank_type' => $data['bank_type'], 'description' => $data['description'], 'instructions' => $data['instructions'], 'is_active' => $data['is_active'] ? 1 : 0];
    }

    private function offeringPayload(array $row): array
    {
        return ['id' => (int) $row['id'], 'subject_id' => (int) $row['subject_id'], 'section' => $row['section'], 'academic_year' => $row['academic_year'], 'academic_term' => $row['academic_term'], 'subject_code' => $row['subject_code'], 'subject_name' => $row['subject_name'], 'program_code' => $row['program_code'], 'program_name' => $row['program_name']];
    }

    private function bankListPayload(array $row): array
    {
        return ['id' => (int) $row['id'], 'code' => $row['code'], 'title' => $row['title'], 'bank_type' => $row['bank_type'], 'description' => $row['description'], 'is_active' => (bool) $row['is_active'], 'questions_count' => (int) ($row['questions_count'] ?? 0), 'total_points' => (int) ($row['total_points'] ?? 0), 'subject_labels' => $row['subject_labels'] ?? '', 'created_at' => $row['created_at'], 'updated_at' => $row['updated_at']];
    }

    private function bankPayload(array $row): array
    {
        $payload = $this->bankListPayload($row); $payload['instructions'] = $row['instructions']; return $payload;
    }

    private function questionPayload(array $row): array
    {
        return ['id' => (int) $row['id'], 'position' => (int) $row['position'], 'question_type' => $row['question_type'], 'question_text' => $row['question_text'], 'options' => $this->decoded($row['options_json']), 'correct_answers' => $this->decoded($row['correct_answers_json']), 'accepted_answers' => $this->decoded($row['accepted_answers_json']), 'case_sensitive' => (bool) $row['case_sensitive'], 'points' => (int) $row['points'], 'is_required' => (bool) $row['is_required'], 'answer_explanation' => $row['answer_explanation']];
    }

    private function encoded(array $value): string
    {
        $json = json_encode(array_values($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) throw new HttpException(422, 'A question answer could not be encoded.');
        return $json;
    }

    private function decoded(?string $value): array
    {
        if ($value === null || $value === '') return [];
        $decoded = json_decode($value, true); return is_array($decoded) ? array_values($decoded) : [];
    }

    private function transaction(callable $callback)
    {
        $owns = !$this->db->inTransaction(); if ($owns) $this->db->beginTransaction();
        try { $result = $callback(); if ($owns) $this->db->commit(); return $result; }
        catch (Throwable $exception) { if ($owns && $this->db->inTransaction()) $this->db->rollBack(); throw $exception; }
    }
}
