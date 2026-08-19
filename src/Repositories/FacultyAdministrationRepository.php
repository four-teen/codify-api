<?php
declare(strict_types=1);

namespace Codify\Repositories;

use Codify\Core\HttpException;
use PDO;
use Throwable;

final class FacultyAdministrationRepository
{
    /** @var PDO */
    private $db;

    public function __construct(PDO $db) { $this->db = $db; }

    public function dashboard(int $facultyId, int $activityPage, int $activityPerPage): array
    {
        $summary = $this->summary($facultyId);
        $subjects = $this->subjects($facultyId);
        $students = $this->students($facultyId);
        $activity = $this->activity($facultyId, $activityPage, $activityPerPage);
        return ['summary' => $summary, 'subjects' => $subjects, 'students' => $students, 'activity' => $activity];
    }

    public function deleteStudents(int $facultyId): array
    {
        $studentStatement = $this->db->prepare("SELECT id, email FROM users WHERE faculty_id = :faculty AND role = 'student'");
        $studentStatement->execute(['faculty' => $facultyId]);
        $students = $studentStatement->fetchAll();
        $studentIds = array_map('intval', array_column($students, 'id'));
        $emails = array_values(array_filter(array_column($students, 'email')));
        $summary = [
            'students' => count($studentIds),
            'enrollments' => $this->count("SELECT COUNT(*) FROM faculty_subject_students fss INNER JOIN users u ON u.id = fss.student_id WHERE u.faculty_id = ? AND u.role = 'student'", [$facultyId]),
            'code_executions' => $this->count("SELECT COUNT(*) FROM code_execution_attempts attempt INNER JOIN users u ON u.id = attempt.student_id WHERE u.faculty_id = ? AND u.role = 'student'", [$facultyId]),
            'devices' => $this->count("SELECT COUNT(*) FROM student_devices device INNER JOIN users u ON u.id = device.student_id WHERE u.faculty_id = ? AND u.role = 'student'", [$facultyId]),
            'device_events' => $this->count("SELECT COUNT(*) FROM student_device_events event INNER JOIN users u ON u.id = event.student_id WHERE u.faculty_id = ? AND u.role = 'student'", [$facultyId]),
            'audit_logs' => $this->count("SELECT COUNT(*) FROM administrator_student_audit_logs log INNER JOIN users u ON u.id = log.subject_user_id WHERE u.faculty_id = ? AND u.role = 'student'", [$facultyId]),
        ];

        if ($studentIds === []) return $summary;

        return $this->transaction(function () use ($facultyId, $studentIds, $emails, $summary) {
            $idPlaceholders = implode(',', array_fill(0, count($studentIds), '?'));
            $this->db->prepare('DELETE FROM administrator_student_audit_logs WHERE subject_user_id IN (' . $idPlaceholders . ')')->execute($studentIds);
            $this->db->prepare('DELETE FROM personal_access_tokens WHERE tokenable_id IN (' . $idPlaceholders . ')')->execute($studentIds);
            if ($emails !== []) {
                $emailPlaceholders = implode(',', array_fill(0, count($emails), '?'));
                $this->db->prepare('DELETE FROM password_resets WHERE email IN (' . $emailPlaceholders . ')')->execute($emails);
            }
            $statement = $this->db->prepare("DELETE FROM users WHERE faculty_id = :faculty AND role = 'student'");
            $statement->execute(['faculty' => $facultyId]);
            return $summary;
        });
    }

    public function deleteSubjects(int $facultyId, ?int $offeringId = null): array
    {
        $sql = 'SELECT id FROM faculty_subjects WHERE faculty_id = :faculty';
        $parameters = ['faculty' => $facultyId];
        if ($offeringId !== null) { $sql .= ' AND id = :offering'; $parameters['offering'] = $offeringId; }
        $statement = $this->db->prepare($sql); $statement->execute($parameters);
        $subjectIds = array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
        if ($offeringId !== null && $subjectIds === []) throw new HttpException(404, 'Faculty subject not found.');
        if ($subjectIds === []) return ['summary' => $this->emptySubjectSummary(), 'stored_names' => []];

        $placeholders = implode(',', array_fill(0, count($subjectIds), '?'));
        $syllabus = $this->db->prepare('SELECT stored_name FROM faculty_subject_syllabi WHERE faculty_subject_id IN (' . $placeholders . ')');
        $syllabus->execute($subjectIds);
        $storedNames = array_values(array_filter($syllabus->fetchAll(PDO::FETCH_COLUMN)));
        $exclusiveProblems = $this->exclusiveContentIds('coding_problem_subjects', 'problem_id', $facultyId, $subjectIds);
        $exclusiveAssessments = $this->exclusiveContentIds('assessment_bank_subjects', 'assessment_bank_id', $facultyId, $subjectIds);
        $problemPlaceholders = $exclusiveProblems === [] ? '' : implode(',', array_fill(0, count($exclusiveProblems), '?'));
        $assessmentPlaceholders = $exclusiveAssessments === [] ? '' : implode(',', array_fill(0, count($exclusiveAssessments), '?'));
        $summary = [
            'subjects' => count($subjectIds),
            'enrollments' => $this->count('SELECT COUNT(*) FROM faculty_subject_students WHERE faculty_subject_id IN (' . $placeholders . ')', $subjectIds),
            'syllabi' => count($storedNames),
            'problem_links' => $this->count('SELECT COUNT(*) FROM coding_problem_subjects WHERE faculty_subject_id IN (' . $placeholders . ')', $subjectIds),
            'assessment_links' => $this->count('SELECT COUNT(*) FROM assessment_bank_subjects WHERE faculty_subject_id IN (' . $placeholders . ')', $subjectIds),
            'coding_problems' => count($exclusiveProblems),
            'problem_test_cases' => $exclusiveProblems === [] ? 0 : $this->count('SELECT COUNT(*) FROM coding_problem_test_cases WHERE problem_id IN (' . $problemPlaceholders . ')', $exclusiveProblems),
            'assessment_banks' => count($exclusiveAssessments),
            'assessment_questions' => $exclusiveAssessments === [] ? 0 : $this->count('SELECT COUNT(*) FROM assessment_bank_questions WHERE assessment_bank_id IN (' . $assessmentPlaceholders . ')', $exclusiveAssessments),
        ];

        return $this->transaction(function () use ($placeholders, $subjectIds, $summary, $storedNames, $exclusiveProblems, $problemPlaceholders, $exclusiveAssessments, $assessmentPlaceholders) {
            if ($exclusiveProblems !== []) $this->db->prepare('DELETE FROM coding_problems WHERE id IN (' . $problemPlaceholders . ')')->execute($exclusiveProblems);
            if ($exclusiveAssessments !== []) $this->db->prepare('DELETE FROM assessment_banks WHERE id IN (' . $assessmentPlaceholders . ')')->execute($exclusiveAssessments);
            $statement = $this->db->prepare('DELETE FROM faculty_subjects WHERE id IN (' . $placeholders . ')');
            $statement->execute($subjectIds);
            return ['summary' => $summary, 'stored_names' => $storedNames];
        });
    }

    public function deleteFaculty(int $facultyId, string $email): array
    {
        return $this->transaction(function () use ($facultyId, $email) {
            $summary = $this->summary($facultyId);
            $studentSummary = $this->deleteStudents($facultyId);
            $subjectResult = $this->deleteSubjects($facultyId);
            $this->db->prepare('DELETE FROM administrator_student_audit_logs WHERE actor_user_id = :faculty')->execute(['faculty' => $facultyId]);
            $this->db->prepare('DELETE FROM personal_access_tokens WHERE tokenable_id = :faculty')->execute(['faculty' => $facultyId]);
            $this->db->prepare('DELETE FROM password_resets WHERE email = :email')->execute(['email' => $email]);
            $this->db->prepare('DELETE FROM assessment_banks WHERE faculty_id = :faculty')->execute(['faculty' => $facultyId]);
            $this->db->prepare('DELETE FROM coding_problems WHERE faculty_id = :faculty')->execute(['faculty' => $facultyId]);
            $this->db->prepare('DELETE FROM users WHERE id = :faculty')->execute(['faculty' => $facultyId]);
            return ['summary' => $summary, 'student_cleanup' => $studentSummary, 'subject_cleanup' => $subjectResult['summary'], 'stored_names' => $subjectResult['stored_names']];
        });
    }

    private function summary(int $facultyId): array
    {
        return [
            'students' => $this->count("SELECT COUNT(*) FROM users WHERE faculty_id = ? AND role = 'student'", [$facultyId]),
            'subjects' => $this->count('SELECT COUNT(*) FROM faculty_subjects WHERE faculty_id = ?', [$facultyId]),
            'enrollments' => $this->count('SELECT COUNT(*) FROM faculty_subject_students fss INNER JOIN faculty_subjects fs ON fs.id = fss.faculty_subject_id WHERE fs.faculty_id = ?', [$facultyId]),
            'syllabi' => $this->count('SELECT COUNT(*) FROM faculty_subject_syllabi syllabus INNER JOIN faculty_subjects fs ON fs.id = syllabus.faculty_subject_id WHERE fs.faculty_id = ?', [$facultyId]),
            'coding_problems' => $this->count('SELECT COUNT(*) FROM coding_problems WHERE faculty_id = ?', [$facultyId]),
            'problem_test_cases' => $this->count('SELECT COUNT(*) FROM coding_problem_test_cases test INNER JOIN coding_problems problem ON problem.id = test.problem_id WHERE problem.faculty_id = ?', [$facultyId]),
            'assessment_banks' => $this->count('SELECT COUNT(*) FROM assessment_banks WHERE faculty_id = ?', [$facultyId]),
            'assessment_questions' => $this->count('SELECT COUNT(*) FROM assessment_bank_questions question INNER JOIN assessment_banks bank ON bank.id = question.assessment_bank_id WHERE bank.faculty_id = ?', [$facultyId]),
            'code_executions' => $this->count("SELECT COUNT(*) FROM code_execution_attempts attempt INNER JOIN users student ON student.id = attempt.student_id WHERE student.faculty_id = ? AND student.role = 'student'", [$facultyId]),
            'device_events' => $this->count("SELECT COUNT(*) FROM student_device_events event INNER JOIN users student ON student.id = event.student_id WHERE student.faculty_id = ? AND student.role = 'student'", [$facultyId]),
            'active_sessions' => $this->count("SELECT COUNT(*) FROM personal_access_tokens token INNER JOIN users account ON account.id = token.tokenable_id WHERE (account.id = ? OR account.faculty_id = ?) AND (token.expires_at IS NULL OR token.expires_at > NOW())", [$facultyId, $facultyId]),
        ];
    }

    private function subjects(int $facultyId): array
    {
        $statement = $this->db->prepare("SELECT fs.id, fs.section, fs.class_schedule, fs.academic_year, fs.academic_term, fs.is_active, fs.created_at, fs.updated_at,
            subject.code AS subject_code, subject.name AS subject_name, program.code AS program_code, program.name AS program_name,
            syllabus.original_name AS syllabus_name,
            (SELECT COUNT(*) FROM faculty_subject_students roster WHERE roster.faculty_subject_id = fs.id) AS students_count,
            (SELECT COUNT(*) FROM coding_problem_subjects link WHERE link.faculty_subject_id = fs.id) AS problems_count,
            (SELECT COUNT(*) FROM assessment_bank_subjects link WHERE link.faculty_subject_id = fs.id) AS assessments_count
            FROM faculty_subjects fs INNER JOIN subjects subject ON subject.id = fs.subject_id
            INNER JOIN programs program ON program.id = subject.program_id
            LEFT JOIN faculty_subject_syllabi syllabus ON syllabus.faculty_subject_id = fs.id
            WHERE fs.faculty_id = :faculty ORDER BY program.code, subject.code, fs.section");
        $statement->execute(['faculty' => $facultyId]);
        $rows = $statement->fetchAll();
        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id']; $row['is_active'] = (bool) $row['is_active'];
            $row['students_count'] = (int) $row['students_count']; $row['problems_count'] = (int) $row['problems_count']; $row['assessments_count'] = (int) $row['assessments_count'];
        }
        unset($row);
        return $rows;
    }

    private function students(int $facultyId): array
    {
        $statement = $this->db->prepare("SELECT student.id, student.name, student.username, student.email, student.is_active, student.created_at, student.updated_at,
            profile.student_number, profile.enrollment_status, program.code AS program_code, program.name AS program_name,
            (SELECT COUNT(*) FROM faculty_subject_students roster WHERE roster.student_id = student.id) AS subjects_count,
            (SELECT COUNT(*) FROM code_execution_attempts attempt WHERE attempt.student_id = student.id) AS code_executions_count,
            (SELECT COUNT(*) FROM student_devices device WHERE device.student_id = student.id) AS devices_count,
            (SELECT COUNT(*) FROM student_device_events event WHERE event.student_id = student.id) AS device_events_count,
            (SELECT MAX(activity_at) FROM (
                SELECT attempted_at AS activity_at, student_id FROM code_execution_attempts
                UNION ALL SELECT occurred_at, student_id FROM student_device_events
            ) activity WHERE activity.student_id = student.id) AS last_activity_at
            FROM users student LEFT JOIN student_profiles profile ON profile.user_id = student.id
            LEFT JOIN programs program ON program.id = profile.program_id
            WHERE student.faculty_id = :faculty AND student.role = 'student'
            ORDER BY student.last_name, student.first_name, student.name");
        $statement->execute(['faculty' => $facultyId]);
        $rows = $statement->fetchAll();
        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id']; $row['is_active'] = (bool) $row['is_active'];
            $row['subjects_count'] = (int) $row['subjects_count']; $row['code_executions_count'] = (int) $row['code_executions_count'];
            $row['devices_count'] = (int) $row['devices_count']; $row['device_events_count'] = (int) $row['device_events_count'];
        }
        unset($row);
        return $rows;
    }

    private function activity(int $facultyId, int $page, int $perPage): array
    {
        $union = "SELECT fs.id AS event_id, 'subject' AS category, 'Subject added' AS action, CONCAT(subject.code, IF(fs.section = '', '', CONCAT(' / ', fs.section))) AS summary, fs.created_at AS event_at FROM faculty_subjects fs INNER JOIN subjects subject ON subject.id = fs.subject_id WHERE fs.faculty_id = ?
            UNION ALL SELECT student.id, 'student', 'Student account created', CONCAT(student.name, ' / ', COALESCE(profile.student_number, student.username, student.email)), student.created_at FROM users student LEFT JOIN student_profiles profile ON profile.user_id = student.id WHERE student.faculty_id = ? AND student.role = 'student'
            UNION ALL SELECT roster.student_id, 'enrollment', 'Student enrolled', CONCAT(student.name, ' in ', subject.code, IF(fs.section = '', '', CONCAT(' / ', fs.section))), roster.created_at FROM faculty_subject_students roster INNER JOIN faculty_subjects fs ON fs.id = roster.faculty_subject_id INNER JOIN subjects subject ON subject.id = fs.subject_id INNER JOIN users student ON student.id = roster.student_id WHERE fs.faculty_id = ?
            UNION ALL SELECT problem.id, 'problem', 'Coding problem created', CONCAT(problem.code, ' - ', problem.title), problem.created_at FROM coding_problems problem WHERE problem.faculty_id = ?
            UNION ALL SELECT bank.id, 'assessment', 'Assessment bank created', CONCAT(bank.code, ' - ', bank.title), bank.created_at FROM assessment_banks bank WHERE bank.faculty_id = ?
            UNION ALL SELECT attempt.id, 'code_execution', 'Code execution', CONCAT(student.name, ' submitted a code run'), attempt.attempted_at FROM code_execution_attempts attempt INNER JOIN users student ON student.id = attempt.student_id WHERE student.faculty_id = ? AND student.role = 'student'
            UNION ALL SELECT event.id, 'device', CONCAT('Device ', REPLACE(event.event_type, '_', ' ')), CONCAT(student.name, ' / ', REPLACE(event.match_status, '_', ' ')), event.occurred_at FROM student_device_events event INNER JOIN users student ON student.id = event.student_id WHERE student.faculty_id = ? AND student.role = 'student'
            UNION ALL SELECT consent.id, 'consent', CONCAT('Device consent ', consent.action), CONCAT(student.name, ' / policy ', consent.policy_version), consent.created_at FROM student_device_consents consent INNER JOIN users student ON student.id = consent.student_id WHERE student.faculty_id = ? AND student.role = 'student'
            UNION ALL SELECT log.id, 'administrator_audit', log.action, log.summary, log.created_at FROM administrator_student_audit_logs log INNER JOIN users student ON student.id = log.subject_user_id WHERE student.faculty_id = ? AND student.role = 'student'";
        $parameters = array_fill(0, 9, $facultyId);
        $totalStatement = $this->db->prepare('SELECT COUNT(*) FROM (' . $union . ') activity');
        $totalStatement->execute($parameters); $total = (int) $totalStatement->fetchColumn();
        $lastPage = max(1, (int) ceil($total / $perPage)); $page = min(max(1, $page), $lastPage); $offset = ($page - 1) * $perPage;
        $statement = $this->db->prepare('SELECT * FROM (' . $union . ") activity ORDER BY event_at DESC, event_id DESC LIMIT {$perPage} OFFSET {$offset}");
        $statement->execute($parameters); $rows = $statement->fetchAll();
        foreach ($rows as &$row) $row['event_id'] = (int) $row['event_id']; unset($row);
        return ['current_page' => $page, 'last_page' => $lastPage, 'per_page' => $perPage, 'total' => $total, 'data' => $rows];
    }

    private function emptySubjectSummary(): array
    { return ['subjects' => 0, 'enrollments' => 0, 'syllabi' => 0, 'problem_links' => 0, 'assessment_links' => 0, 'coding_problems' => 0, 'problem_test_cases' => 0, 'assessment_banks' => 0, 'assessment_questions' => 0]; }

    private function exclusiveContentIds(string $table, string $contentColumn, int $facultyId, array $subjectIds): array
    {
        $placeholders = implode(',', array_fill(0, count($subjectIds), '?'));
        $ownerTable = $table === 'coding_problem_subjects' ? 'coding_problems' : 'assessment_banks';
        $sql = "SELECT DISTINCT link.{$contentColumn} FROM {$table} link INNER JOIN {$ownerTable} content ON content.id = link.{$contentColumn}
            WHERE content.faculty_id = ? AND link.faculty_subject_id IN ({$placeholders})
            AND NOT EXISTS (SELECT 1 FROM {$table} shared WHERE shared.{$contentColumn} = link.{$contentColumn} AND shared.faculty_subject_id NOT IN ({$placeholders}))";
        $statement = $this->db->prepare($sql);
        $statement->execute(array_merge([$facultyId], $subjectIds, $subjectIds));
        return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    private function count(string $sql, array $parameters): int
    { $statement = $this->db->prepare($sql); $statement->execute($parameters); return (int) $statement->fetchColumn(); }

    private function transaction(callable $callback)
    {
        $owns = !$this->db->inTransaction();
        if ($owns) $this->db->beginTransaction();
        try { $result = $callback(); if ($owns) $this->db->commit(); return $result; }
        catch (Throwable $exception) { if ($owns && $this->db->inTransaction()) $this->db->rollBack(); throw $exception; }
    }
}
