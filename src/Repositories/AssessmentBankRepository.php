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
            (SELECT COUNT(DISTINCT attempt.faculty_subject_id, attempt.student_id) FROM student_assessment_attempts attempt WHERE attempt.assessment_bank_id = bank.id) AS respondents_count,
            (SELECT COUNT(*) FROM student_assessment_attempts attempt WHERE attempt.assessment_bank_id = bank.id) AS submissions_count,
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

    public function responses(int $facultyId, int $bankId): array
    {
        $bank = $this->bank($facultyId, $bankId);
        $statement = $this->db->prepare("SELECT latest.id AS attempt_id, latest.auto_score, latest.total_points,
            latest.pending_review_points, latest.grading_status, latest.submitted_at, grouped.attempts_count,
            grouped.average_score, grouped.average_total_points, grouped.final_score_percent,
            COALESCE(permission.additional_attempts, 0) AS additional_attempts,
            student.id AS student_id, student.name AS student_name, student.username,
            profile.student_number, fs.id AS faculty_subject_id, fs.section,
            subject.code AS subject_code, subject.name AS subject_name, program.code AS program_code
            FROM (
                SELECT faculty_subject_id, student_id, COUNT(*) AS attempts_count, MAX(id) AS latest_attempt_id,
                    AVG(auto_score) AS average_score, AVG(total_points) AS average_total_points,
                    AVG(CASE WHEN total_points > 0 THEN (auto_score / total_points) * 100 ELSE 0 END) AS final_score_percent
                FROM student_assessment_attempts
                WHERE assessment_bank_id = :group_bank
                GROUP BY faculty_subject_id, student_id
            ) grouped
            INNER JOIN student_assessment_attempts latest ON latest.id = grouped.latest_attempt_id
            INNER JOIN users student ON student.id = grouped.student_id AND student.role = 'student'
            LEFT JOIN student_profiles profile ON profile.user_id = student.id
            INNER JOIN faculty_subjects fs ON fs.id = grouped.faculty_subject_id AND fs.faculty_id = :faculty
            INNER JOIN subjects subject ON subject.id = fs.subject_id
            INNER JOIN programs program ON program.id = subject.program_id
            LEFT JOIN student_assessment_retake_permissions permission ON permission.assessment_bank_id = :permission_bank
                AND permission.faculty_subject_id = grouped.faculty_subject_id AND permission.student_id = grouped.student_id
            ORDER BY student.last_name, student.first_name, student.name, subject.code, fs.section");
        $statement->execute(['group_bank' => $bankId, 'faculty' => $facultyId, 'permission_bank' => $bankId]);
        $rows = []; $submissions = 0; $pending = 0; $percentTotal = 0.0;
        foreach ($statement->fetchAll() as $row) {
            $totalPoints = (int) $row['total_points']; $autoScore = (int) $row['auto_score'];
            $percent = $totalPoints > 0 ? (int) round(($autoScore / $totalPoints) * 100) : 0;
            $attempts = (int) $row['attempts_count'];
            $additional = (int) $row['additional_attempts'];
            $remaining = max(0, $additional - max(0, $attempts - 1));
            $finalPercent = round((float) $row['final_score_percent'], 1);
            $submissions += $attempts; $percentTotal += $finalPercent;
            if ($row['grading_status'] === 'pending_review') $pending++;
            $rows[] = [
                'attempt_id' => (int) $row['attempt_id'], 'student_id' => (int) $row['student_id'],
                'student_name' => $row['student_name'], 'student_number' => $row['student_number'] ?: $row['username'],
                'faculty_subject_id' => (int) $row['faculty_subject_id'], 'program_code' => $row['program_code'],
                'subject_code' => $row['subject_code'], 'subject_name' => $row['subject_name'], 'section' => $row['section'],
                'attempts_count' => $attempts, 'auto_score' => $autoScore, 'total_points' => $totalPoints,
                'score_percent' => $percent, 'pending_review_points' => (int) $row['pending_review_points'],
                'average_score' => round((float) $row['average_score'], 2),
                'average_total_points' => round((float) $row['average_total_points'], 2),
                'final_score_percent' => $finalPercent,
                'retakes_remaining' => $remaining,
                'can_grant_retake' => $attempts > 0 && $remaining === 0,
                'grading_status' => $row['grading_status'], 'submitted_at' => $row['submitted_at'],
            ];
        }
        $respondents = count($rows);
        return [
            'bank' => ['id' => $bank['id'], 'code' => $bank['code'], 'title' => $bank['title'], 'bank_type' => $bank['bank_type']],
            'summary' => [
                'respondents' => $respondents, 'submissions' => $submissions, 'pending_review' => $pending,
                'average_percent' => $respondents > 0 ? (int) round($percentTotal / $respondents) : 0,
            ],
            'students' => $rows,
        ];
    }

    public function grantRetakes(int $facultyId, int $bankId, array $students): array
    {
        $bank = $this->bank($facultyId, $bankId);
        return $this->transaction(function () use ($facultyId, $bankId, $bank, $students): array {
            $eligible = $this->db->prepare("SELECT bank.id FROM faculty_subject_students enrollment
                INNER JOIN faculty_subjects fs ON fs.id = enrollment.faculty_subject_id
                INNER JOIN assessment_bank_subjects link ON link.faculty_subject_id = fs.id
                INNER JOIN assessment_banks bank ON bank.id = link.assessment_bank_id AND bank.faculty_id = fs.faculty_id
                WHERE bank.id = :bank AND bank.faculty_id = :faculty AND bank.is_active = 1
                    AND fs.id = :offering AND fs.is_active = 1 AND enrollment.student_id = :student
                LIMIT 1 FOR UPDATE");
            $count = $this->db->prepare('SELECT COUNT(*) FROM student_assessment_attempts WHERE assessment_bank_id = :bank AND faculty_subject_id = :offering AND student_id = :student');
            $permission = $this->db->prepare('SELECT additional_attempts FROM student_assessment_retake_permissions WHERE assessment_bank_id = :bank AND faculty_subject_id = :offering AND student_id = :student FOR UPDATE');
            $grant = $this->db->prepare('INSERT INTO student_assessment_retake_permissions
                (assessment_bank_id, faculty_subject_id, student_id, additional_attempts, granted_by, granted_at, updated_at)
                VALUES (:bank, :offering, :student, :additional_attempts, :faculty, NOW(), NOW())
                ON DUPLICATE KEY UPDATE additional_attempts = VALUES(additional_attempts), granted_by = VALUES(granted_by), granted_at = NOW(), updated_at = NOW()');
            $granted = 0; $alreadyAllowed = 0;
            foreach ($students as $selection) {
                $parameters = ['bank' => $bankId, 'faculty' => $facultyId, 'offering' => $selection['faculty_subject_id'], 'student' => $selection['student_id']];
                $eligible->execute($parameters);
                if (!$eligible->fetchColumn()) throw new HttpException(422, 'One or more selected students are not eligible for this assessment.');
                $attemptParameters = ['bank' => $bankId, 'offering' => $selection['faculty_subject_id'], 'student' => $selection['student_id']];
                $count->execute($attemptParameters); $attempts = (int) $count->fetchColumn();
                if ($attempts < 1) throw new HttpException(422, 'One or more selected students have not submitted this assessment yet.');
                $permission->execute($attemptParameters); $additional = (int) ($permission->fetchColumn() ?: 0);
                if ($additional - max(0, $attempts - 1) > 0) { $alreadyAllowed++; continue; }
                $grant->execute($attemptParameters + ['additional_attempts' => $attempts, 'faculty' => $facultyId]);
                $granted++;
            }
            return [
                'bank' => ['id' => $bank['id'], 'code' => $bank['code'], 'title' => $bank['title'], 'bank_type' => $bank['bank_type']],
                'selected' => count($students), 'granted' => $granted, 'already_allowed' => $alreadyAllowed,
            ];
        });
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
        return ['id' => (int) $row['id'], 'code' => $row['code'], 'title' => $row['title'], 'bank_type' => $row['bank_type'], 'description' => $row['description'], 'is_active' => (bool) $row['is_active'], 'questions_count' => (int) ($row['questions_count'] ?? 0), 'total_points' => (int) ($row['total_points'] ?? 0), 'respondents_count' => (int) ($row['respondents_count'] ?? 0), 'submissions_count' => (int) ($row['submissions_count'] ?? 0), 'subject_labels' => $row['subject_labels'] ?? '', 'created_at' => $row['created_at'], 'updated_at' => $row['updated_at']];
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
