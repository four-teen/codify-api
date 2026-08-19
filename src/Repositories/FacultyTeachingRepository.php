<?php
declare(strict_types=1);

namespace Codify\Repositories;

use Codify\Core\HttpException;
use PDO;
use Throwable;

final class FacultyTeachingRepository
{
    /** @var PDO */
    private $db;

    public function __construct(PDO $db) { $this->db = $db; }

    public function offerings(int $facultyId, string $academicYear, string $academicTerm): array
    {
        $statement = $this->db->prepare($this->offeringSql() . ' WHERE fs.faculty_id = :faculty AND fs.academic_year = :academic_year AND fs.academic_term = :academic_term ORDER BY p.code, s.code, fs.section');
        $statement->execute(['faculty' => $facultyId, 'academic_year' => $academicYear, 'academic_term' => $academicTerm]);
        return array_map([$this, 'offeringPayload'], $statement->fetchAll());
    }

    public function offering(int $facultyId, int $offeringId): array
    {
        $statement = $this->db->prepare($this->offeringSql() . ' WHERE fs.id = :id AND fs.faculty_id = :faculty LIMIT 1');
        $statement->execute(['id' => $offeringId, 'faculty' => $facultyId]);
        $row = $statement->fetch();
        if (!$row) throw new HttpException(404, 'Faculty subject not found.');
        return $this->offeringPayload($row);
    }

    public function createOffering(int $facultyId, int $subjectId, string $section, ?string $classSchedule, string $academicYear, string $academicTerm): array
    {
        $section = $this->upper($section); $classSchedule = $classSchedule === null ? null : $this->upper($classSchedule);
        $this->assignedSubject($facultyId, $subjectId);
        $duplicate = $this->db->prepare('SELECT id FROM faculty_subjects WHERE faculty_id = :faculty AND subject_id = :subject AND section = :section AND academic_year = :academic_year AND academic_term = :academic_term LIMIT 1');
        $duplicate->execute(['faculty' => $facultyId, 'subject' => $subjectId, 'section' => $section, 'academic_year' => $academicYear, 'academic_term' => $academicTerm]);
        if ($duplicate->fetchColumn()) throw new HttpException(422, 'This subject and section are already in your current teaching list.', ['subject_id' => ['Choose another subject or section.']]);
        $statement = $this->db->prepare('INSERT INTO faculty_subjects (faculty_id, subject_id, section, class_schedule, academic_year, academic_term, is_active, created_at, updated_at) VALUES (:faculty, :subject, :section, :class_schedule, :academic_year, :academic_term, 1, NOW(), NOW())');
        $statement->execute(['faculty' => $facultyId, 'subject' => $subjectId, 'section' => $section, 'class_schedule' => $classSchedule, 'academic_year' => $academicYear, 'academic_term' => $academicTerm]);
        return $this->offering($facultyId, (int) $this->db->lastInsertId());
    }

    public function syllabus(int $facultyId, int $offeringId): ?array
    {
        $this->offering($facultyId, $offeringId);
        $statement = $this->db->prepare('SELECT faculty_subject_id, original_name, stored_name, mime_type, size_bytes, uploaded_at, updated_at FROM faculty_subject_syllabi WHERE faculty_subject_id = :offering LIMIT 1');
        $statement->execute(['offering' => $offeringId]); $row = $statement->fetch();
        if (!$row) return null;
        $row['faculty_subject_id'] = (int) $row['faculty_subject_id']; $row['size_bytes'] = (int) $row['size_bytes']; return $row;
    }

    public function saveSyllabus(int $facultyId, int $offeringId, array $file): array
    {
        $this->offering($facultyId, $offeringId);
        $statement = $this->db->prepare('INSERT INTO faculty_subject_syllabi (faculty_subject_id, original_name, stored_name, mime_type, size_bytes, uploaded_at, updated_at) VALUES (:offering, :original_name, :stored_name, :mime_type, :size_bytes, NOW(), NOW()) ON DUPLICATE KEY UPDATE original_name = VALUES(original_name), stored_name = VALUES(stored_name), mime_type = VALUES(mime_type), size_bytes = VALUES(size_bytes), uploaded_at = NOW(), updated_at = NOW()');
        $statement->execute(['offering' => $offeringId, 'original_name' => $file['original_name'], 'stored_name' => $file['stored_name'], 'mime_type' => $file['mime_type'], 'size_bytes' => $file['size_bytes']]);
        return $this->offering($facultyId, $offeringId);
    }

    public function removeSyllabus(int $facultyId, int $offeringId): ?array
    {
        $syllabus = $this->syllabus($facultyId, $offeringId);
        if ($syllabus === null) return null;
        $this->db->prepare('DELETE FROM faculty_subject_syllabi WHERE faculty_subject_id = :offering')->execute(['offering' => $offeringId]);
        return $syllabus;
    }

    public function deleteOffering(int $facultyId, int $offeringId): void
    {
        $this->offering($facultyId, $offeringId);
        $this->transaction(function () use ($facultyId, $offeringId) {
            $this->db->prepare('DELETE problem FROM coding_problems problem INNER JOIN coding_problem_subjects link ON link.problem_id = problem.id WHERE problem.faculty_id = :faculty AND link.faculty_subject_id = :offering AND NOT EXISTS (SELECT 1 FROM coding_problem_subjects shared WHERE shared.problem_id = problem.id AND shared.faculty_subject_id <> :shared_offering)')->execute(['faculty' => $facultyId, 'offering' => $offeringId, 'shared_offering' => $offeringId]);
            $this->db->prepare('DELETE bank FROM assessment_banks bank INNER JOIN assessment_bank_subjects link ON link.assessment_bank_id = bank.id WHERE bank.faculty_id = :faculty AND link.faculty_subject_id = :offering AND NOT EXISTS (SELECT 1 FROM assessment_bank_subjects shared WHERE shared.assessment_bank_id = bank.id AND shared.faculty_subject_id <> :shared_offering)')->execute(['faculty' => $facultyId, 'offering' => $offeringId, 'shared_offering' => $offeringId]);
            $this->db->prepare('DELETE FROM faculty_subjects WHERE id = :id AND faculty_id = :faculty')->execute(['id' => $offeringId, 'faculty' => $facultyId]);
        });
    }

    public function students(int $facultyId, int $offeringId): array
    {
        $this->offering($facultyId, $offeringId);
        $statement = $this->db->prepare("SELECT u.id, u.faculty_id, u.first_name, u.last_name, u.name, u.username, u.email, u.is_active, u.must_change_password,
            sp.program_id, sp.student_number, sp.gender, sp.mobile_number, sp.course_label, sp.enrollment_status,
            fss.source, fss.created_at AS enrolled_at,
            (SELECT COUNT(*) FROM student_device_events login_event WHERE login_event.student_id = u.id AND login_event.event_type = 'session_start') AS login_count,
            (SELECT MAX(login_event.occurred_at) FROM student_device_events login_event WHERE login_event.student_id = u.id AND login_event.event_type = 'session_start') AS latest_login_at
            FROM faculty_subject_students fss
            INNER JOIN faculty_subjects fs ON fs.id = fss.faculty_subject_id
            INNER JOIN users u ON u.id = fss.student_id
            LEFT JOIN student_profiles sp ON sp.user_id = u.id
            WHERE fs.id = :offering AND fs.faculty_id = :faculty AND u.role = 'student'
            ORDER BY u.last_name, u.first_name, u.name");
        $statement->execute(['offering' => $offeringId, 'faculty' => $facultyId]);
        return array_map([$this, 'studentPayload'], $statement->fetchAll());
    }

    public function studentMonitoring(int $facultyId, int $offeringId, int $studentId): array
    {
        $student = $this->student($facultyId, $offeringId, $studentId);
        $periodEnd = new \DateTimeImmutable('today');
        $periodStart = $periodEnd->modify('-29 days');
        $parameters = [
            'student' => $studentId,
            'period_start' => $periodStart->format('Y-m-d'),
            'period_end' => $periodEnd->format('Y-m-d'),
        ];

        $statement = $this->db->prepare("SELECT DATE(event.occurred_at) AS activity_date,
            COUNT(DISTINCT event.device_id) AS devices_used,
            MAX(CASE WHEN event.device_id = (
                SELECT original.id FROM student_devices original
                WHERE original.student_id = :original_student
                ORDER BY original.first_seen_at, original.id LIMIT 1
            ) THEN 1 ELSE 0 END) AS original_device_used
            FROM student_device_events event
            WHERE event.student_id = :student AND event.event_type = 'session_start'
                AND event.occurred_at >= :period_start
                AND event.occurred_at < DATE_ADD(:period_end, INTERVAL 1 DAY)
            GROUP BY DATE(event.occurred_at)
            ORDER BY activity_date");
        $statement->execute($parameters + ['original_student' => $studentId]);
        $recordedDays = [];
        foreach ($statement->fetchAll() as $row) {
            $recordedDays[$row['activity_date']] = [
                'devices_used' => (int) $row['devices_used'],
                'original_device_used' => (bool) $row['original_device_used'],
            ];
        }

        $days = [];
        for ($date = $periodStart; $date <= $periodEnd; $date = $date->modify('+1 day')) {
            $key = $date->format('Y-m-d');
            $days[] = [
                'date' => $key,
                'devices_used' => $recordedDays[$key]['devices_used'] ?? 0,
                'original_device_used' => $recordedDays[$key]['original_device_used'] ?? false,
            ];
        }

        $summary = $this->db->prepare("SELECT COUNT(DISTINCT event.device_id) AS devices_used,
            COUNT(DISTINCT DATE(event.occurred_at)) AS active_days
            FROM student_device_events event
            WHERE event.student_id = :student AND event.event_type = 'session_start'
                AND event.occurred_at >= :period_start
                AND event.occurred_at < DATE_ADD(:period_end, INTERVAL 1 DAY)");
        $summary->execute($parameters);
        $summaryRow = $summary->fetch() ?: ['devices_used' => 0, 'active_days' => 0];

        return [
            'student' => ['id' => $student['id'], 'name' => $student['name'], 'student_number' => $student['student_number']],
            'period' => ['start' => $periodStart->format('Y-m-d'), 'end' => $periodEnd->format('Y-m-d'), 'days' => 30],
            'summary' => [
                'total_logins' => (int) $student['login_count'],
                'devices_used' => (int) $summaryRow['devices_used'],
                'active_days' => (int) $summaryRow['active_days'],
                'latest_login_at' => $student['latest_login_at'],
            ],
            'days' => $days,
        ];
    }

    public function student(int $facultyId, int $offeringId, int $studentId): array
    {
        $statement = $this->db->prepare("SELECT u.id, u.faculty_id, u.first_name, u.last_name, u.name, u.username, u.email, u.is_active, u.must_change_password,
            sp.program_id, sp.student_number, sp.gender, sp.mobile_number, sp.course_label, sp.enrollment_status,
            fss.source, fss.created_at AS enrolled_at,
            (SELECT COUNT(*) FROM student_device_events login_event WHERE login_event.student_id = u.id AND login_event.event_type = 'session_start') AS login_count,
            (SELECT MAX(login_event.occurred_at) FROM student_device_events login_event WHERE login_event.student_id = u.id AND login_event.event_type = 'session_start') AS latest_login_at
            FROM faculty_subject_students fss
            INNER JOIN faculty_subjects fs ON fs.id = fss.faculty_subject_id
            INNER JOIN users u ON u.id = fss.student_id
            LEFT JOIN student_profiles sp ON sp.user_id = u.id
            WHERE fs.id = :offering AND fs.faculty_id = :faculty AND u.id = :student AND u.role = 'student' LIMIT 1");
        $statement->execute(['offering' => $offeringId, 'faculty' => $facultyId, 'student' => $studentId]);
        $row = $statement->fetch();
        if (!$row) throw new HttpException(404, 'Student is not enrolled in this faculty subject.');
        return $this->studentPayload($row);
    }

    public function studentProfile(int $studentId): ?array
    {
        $statement = $this->db->prepare('SELECT * FROM student_profiles WHERE user_id = :id LIMIT 1');
        $statement->execute(['id' => $studentId]);
        $row = $statement->fetch();
        if (!$row) return null;
        $row['user_id'] = (int) $row['user_id'];
        $row['program_id'] = (int) $row['program_id'];
        return $row;
    }

    public function syncStudentProfile(int $studentId, int $programId, array $profile): void
    {
        $statement = $this->db->prepare('INSERT INTO student_profiles (user_id, program_id, student_number, gender, mobile_number, course_label, enrollment_status, created_at, updated_at) VALUES (:user, :program, :student_number, :gender, :mobile_number, :course_label, :enrollment_status, NOW(), NOW()) ON DUPLICATE KEY UPDATE program_id = VALUES(program_id), student_number = COALESCE(VALUES(student_number), student_number), gender = COALESCE(VALUES(gender), gender), mobile_number = COALESCE(VALUES(mobile_number), mobile_number), course_label = COALESCE(VALUES(course_label), course_label), enrollment_status = COALESCE(VALUES(enrollment_status), enrollment_status), updated_at = NOW()');
        $statement->execute([
            'user' => $studentId, 'program' => $programId, 'student_number' => $profile['student_number'],
            'gender' => $profile['gender'], 'mobile_number' => $profile['mobile_number'],
            'course_label' => $profile['course_label'], 'enrollment_status' => $profile['enrollment_status'],
        ]);
    }

    public function enroll(int $facultyId, int $offeringId, int $studentId, string $source): bool
    {
        $this->offering($facultyId, $offeringId);
        $statement = $this->db->prepare('INSERT IGNORE INTO faculty_subject_students (faculty_subject_id, student_id, source, created_at) VALUES (:offering, :student, :source, NOW())');
        $statement->execute(['offering' => $offeringId, 'student' => $studentId, 'source' => $source]);
        return $statement->rowCount() > 0;
    }

    public function unenroll(int $facultyId, int $offeringId, int $studentId): void
    {
        $statement = $this->db->prepare('DELETE fss FROM faculty_subject_students fss INNER JOIN faculty_subjects fs ON fs.id = fss.faculty_subject_id WHERE fss.faculty_subject_id = :offering AND fss.student_id = :student AND fs.faculty_id = :faculty');
        $statement->execute(['offering' => $offeringId, 'student' => $studentId, 'faculty' => $facultyId]);
        if ($statement->rowCount() < 1) throw new HttpException(404, 'Student is not enrolled in this faculty subject.');
    }

    public function unenrollAll(int $facultyId, int $offeringId): int
    {
        $this->offering($facultyId, $offeringId);
        $statement = $this->db->prepare('DELETE FROM faculty_subject_students WHERE faculty_subject_id = :offering');
        $statement->execute(['offering' => $offeringId]);
        return $statement->rowCount();
    }

    public function studentAssessmentRetakes(int $facultyId, int $offeringId, int $studentId): array
    {
        $this->student($facultyId, $offeringId, $studentId);
        $statement = $this->db->prepare("SELECT bank.id, bank.code, bank.title, bank.bank_type,
            (SELECT COUNT(*) FROM student_assessment_attempts attempt
                WHERE attempt.assessment_bank_id = bank.id AND attempt.faculty_subject_id = :attempt_offering AND attempt.student_id = :attempt_student) AS attempts_count,
            (SELECT attempt.auto_score FROM student_assessment_attempts attempt
                WHERE attempt.assessment_bank_id = bank.id AND attempt.faculty_subject_id = :score_offering AND attempt.student_id = :score_student
                ORDER BY attempt.id DESC LIMIT 1) AS latest_score,
            (SELECT attempt.total_points FROM student_assessment_attempts attempt
                WHERE attempt.assessment_bank_id = bank.id AND attempt.faculty_subject_id = :points_offering AND attempt.student_id = :points_student
                ORDER BY attempt.id DESC LIMIT 1) AS latest_total_points,
            COALESCE(permission.additional_attempts, 0) AS additional_attempts
            FROM assessment_banks bank
            INNER JOIN assessment_bank_subjects link ON link.assessment_bank_id = bank.id
            INNER JOIN faculty_subjects fs ON fs.id = link.faculty_subject_id AND fs.faculty_id = bank.faculty_id
            LEFT JOIN student_assessment_retake_permissions permission ON permission.assessment_bank_id = bank.id
                AND permission.faculty_subject_id = fs.id AND permission.student_id = :permission_student
            WHERE fs.id = :offering AND fs.faculty_id = :faculty AND fs.is_active = 1 AND bank.is_active = 1
            ORDER BY bank.updated_at DESC, bank.code");
        $statement->execute([
            'attempt_offering' => $offeringId, 'attempt_student' => $studentId,
            'score_offering' => $offeringId, 'score_student' => $studentId,
            'points_offering' => $offeringId, 'points_student' => $studentId,
            'permission_student' => $studentId, 'offering' => $offeringId, 'faculty' => $facultyId,
        ]);
        return array_map(static function (array $row): array {
            $attempts = (int) $row['attempts_count'];
            $additional = (int) $row['additional_attempts'];
            $remaining = max(0, $additional - max(0, $attempts - 1));
            return [
                'id' => (int) $row['id'], 'code' => $row['code'], 'title' => $row['title'], 'bank_type' => $row['bank_type'],
                'attempts_count' => $attempts,
                'latest_score' => $row['latest_score'] === null ? null : (int) $row['latest_score'],
                'latest_total_points' => $row['latest_total_points'] === null ? null : (int) $row['latest_total_points'],
                'retakes_remaining' => $remaining,
                'can_grant_retake' => $attempts > 0 && $remaining === 0,
            ];
        }, $statement->fetchAll());
    }

    public function grantStudentAssessmentRetake(int $facultyId, int $offeringId, int $studentId, int $assessmentId): array
    {
        return $this->transaction(function () use ($facultyId, $offeringId, $studentId, $assessmentId): array {
            $eligible = $this->db->prepare("SELECT bank.id FROM faculty_subject_students enrollment
                INNER JOIN faculty_subjects fs ON fs.id = enrollment.faculty_subject_id
                INNER JOIN assessment_bank_subjects link ON link.faculty_subject_id = fs.id
                INNER JOIN assessment_banks bank ON bank.id = link.assessment_bank_id AND bank.faculty_id = fs.faculty_id
                WHERE fs.id = :offering AND fs.faculty_id = :faculty AND fs.is_active = 1 AND enrollment.student_id = :student
                    AND bank.id = :assessment AND bank.is_active = 1
                LIMIT 1 FOR UPDATE");
            $eligible->execute(['offering' => $offeringId, 'faculty' => $facultyId, 'student' => $studentId, 'assessment' => $assessmentId]);
            if (!$eligible->fetchColumn()) throw new HttpException(404, 'Active assessment not found for this student and subject.');

            $count = $this->db->prepare('SELECT COUNT(*) FROM student_assessment_attempts WHERE assessment_bank_id = :assessment AND faculty_subject_id = :offering AND student_id = :student');
            $count->execute(['assessment' => $assessmentId, 'offering' => $offeringId, 'student' => $studentId]);
            $attempts = (int) $count->fetchColumn();
            if ($attempts < 1) throw new HttpException(422, 'This student has not submitted the assessment yet, so a retake is not needed.');

            $permission = $this->db->prepare('SELECT additional_attempts FROM student_assessment_retake_permissions WHERE assessment_bank_id = :assessment AND faculty_subject_id = :offering AND student_id = :student FOR UPDATE');
            $permission->execute(['assessment' => $assessmentId, 'offering' => $offeringId, 'student' => $studentId]);
            $additional = (int) ($permission->fetchColumn() ?: 0);
            if ($additional - max(0, $attempts - 1) > 0) throw new HttpException(409, 'This student already has an unused retake for this assessment.');

            $grant = $this->db->prepare('INSERT INTO student_assessment_retake_permissions
                (assessment_bank_id, faculty_subject_id, student_id, additional_attempts, granted_by, granted_at, updated_at)
                VALUES (:assessment, :offering, :student, :additional_attempts, :faculty, NOW(), NOW())
                ON DUPLICATE KEY UPDATE additional_attempts = VALUES(additional_attempts), granted_by = VALUES(granted_by), granted_at = NOW(), updated_at = NOW()');
            $grant->execute(['assessment' => $assessmentId, 'offering' => $offeringId, 'student' => $studentId, 'additional_attempts' => $attempts, 'faculty' => $facultyId]);

            foreach ($this->studentAssessmentRetakes($facultyId, $offeringId, $studentId) as $assessment) {
                if ((int) $assessment['id'] === $assessmentId) return $assessment;
            }
            throw new HttpException(404, 'Active assessment not found for this student and subject.');
        });
    }

    public function assignedSubject(int $facultyId, int $subjectId): array
    {
        $statement = $this->db->prepare('SELECT s.id, s.program_id, s.code, s.name, s.units, p.code AS program_code, p.name AS program_name, co.code AS college_code, co.name AS college_name FROM subjects s INNER JOIN programs p ON p.id = s.program_id INNER JOIN colleges co ON co.id = p.college_id INNER JOIN faculty_programs fp ON fp.program_id = p.id INNER JOIN faculty_profiles profile ON profile.user_id = fp.faculty_id WHERE fp.faculty_id = :faculty AND s.id = :subject AND co.campus_id = profile.campus_id AND s.is_active = 1 AND p.is_active = 1 LIMIT 1');
        $statement->execute(['faculty' => $facultyId, 'subject' => $subjectId]);
        $row = $statement->fetch();
        if (!$row) throw new HttpException(403, 'The selected subject is outside your assigned programs.');
        $row['id'] = (int) $row['id']; $row['program_id'] = (int) $row['program_id']; $row['units'] = (int) $row['units'];
        return $row;
    }

    public function transaction(callable $callback)
    {
        $owns = !$this->db->inTransaction();
        if ($owns) $this->db->beginTransaction();
        try { $result = $callback(); if ($owns) $this->db->commit(); return $result; }
        catch (Throwable $exception) { if ($owns && $this->db->inTransaction()) $this->db->rollBack(); throw $exception; }
    }

    private function offeringSql(): string
    {
        return "SELECT fs.id, fs.faculty_id, fs.subject_id, fs.section, fs.class_schedule, fs.academic_year, fs.academic_term, fs.is_active, fs.created_at, fs.updated_at,
            s.program_id, s.code AS subject_code, s.name AS subject_name, s.units,
            p.code AS program_code, p.name AS program_name, co.code AS college_code, co.name AS college_name,
            syllabus.original_name AS syllabus_original_name, syllabus.size_bytes AS syllabus_size_bytes, syllabus.uploaded_at AS syllabus_uploaded_at,
            (SELECT COUNT(*) FROM faculty_subject_students roster WHERE roster.faculty_subject_id = fs.id) AS students_count
            FROM faculty_subjects fs
            INNER JOIN subjects s ON s.id = fs.subject_id
            INNER JOIN programs p ON p.id = s.program_id
            INNER JOIN colleges co ON co.id = p.college_id
            LEFT JOIN faculty_subject_syllabi syllabus ON syllabus.faculty_subject_id = fs.id";
    }

    private function offeringPayload(array $row): array
    {
        return [
            'id' => (int) $row['id'], 'faculty_id' => (int) $row['faculty_id'], 'subject_id' => (int) $row['subject_id'],
            'section' => $row['section'], 'class_schedule' => $row['class_schedule'], 'academic_year' => $row['academic_year'],
            'academic_term' => $row['academic_term'], 'is_active' => (bool) $row['is_active'], 'program_id' => (int) $row['program_id'],
            'subject_code' => $row['subject_code'], 'subject_name' => $row['subject_name'], 'units' => (int) $row['units'],
            'program_code' => $row['program_code'], 'program_name' => $row['program_name'], 'college_code' => $row['college_code'],
            'college_name' => $row['college_name'], 'students_count' => (int) $row['students_count'],
            'created_at' => $row['created_at'], 'updated_at' => $row['updated_at'],
            'syllabus' => $row['syllabus_original_name'] === null ? null : ['original_name' => $row['syllabus_original_name'], 'size_bytes' => (int) $row['syllabus_size_bytes'], 'uploaded_at' => $row['syllabus_uploaded_at']],
        ];
    }

    private function upper(string $value): string { return function_exists('mb_strtoupper') ? mb_strtoupper(trim($value), 'UTF-8') : strtoupper(trim($value)); }

    private function studentPayload(array $row): array
    {
        return [
            'id' => (int) $row['id'], 'faculty_id' => (int) $row['faculty_id'], 'first_name' => $row['first_name'],
            'last_name' => $row['last_name'], 'name' => $row['name'], 'username' => $row['username'], 'email' => $row['email'],
            'is_active' => (bool) $row['is_active'], 'must_change_password' => (bool) $row['must_change_password'],
            'program_id' => $row['program_id'] === null ? null : (int) $row['program_id'], 'student_number' => $row['student_number'],
            'gender' => $row['gender'], 'mobile_number' => $row['mobile_number'], 'course_label' => $row['course_label'],
            'enrollment_status' => $row['enrollment_status'], 'source' => $row['source'], 'enrolled_at' => $row['enrolled_at'],
            'login_count' => (int) ($row['login_count'] ?? 0), 'latest_login_at' => $row['latest_login_at'] ?? null,
        ];
    }
}
