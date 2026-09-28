<?php
declare(strict_types=1);

namespace Codify\Repositories;

use Codify\Core\HttpException;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;

final class SubjectAttendanceRepository
{
    private $db;

    public function __construct(PDO $db) { $this->db = $db; }

    public function today(): string
    {
        $settings = (new SystemSettingRepository($this->db))->current();
        return (new DateTimeImmutable('now', new DateTimeZone($settings['timezone'] ?? 'Asia/Manila')))->format('Y-m-d');
    }

    private function validateDate(string $date): void
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$parsed || $parsed->format('Y-m-d') !== $date || $date < '1000-01-01' || $date > $this->today()) {
            throw new HttpException(422, 'Choose a valid attendance date on or before today.');
        }
    }

    private function offering(int $facultyId, int $offeringId): array
    {
        return (new FacultyTeachingRepository($this->db))->offering($facultyId, $offeringId);
    }

    private function roster(int $offeringId): array
    {
        $query = $this->db->prepare("SELECT u.id AS student_id, COALESCE(p.student_number, u.username) AS student_number,
            u.name AS student_name, u.first_name, u.last_name FROM faculty_subject_students enrollment
            INNER JOIN users u ON u.id = enrollment.student_id AND u.role = 'student'
            LEFT JOIN student_profiles p ON p.user_id = u.id
            WHERE enrollment.faculty_subject_id = :offering ORDER BY u.last_name, u.first_name, u.id");
        $query->execute(['offering' => $offeringId]);
        return array_map(static function (array $row): array {
            $row['student_id'] = (int) $row['student_id']; $row['status'] = 'absent'; return $row;
        }, $query->fetchAll());
    }

    private function session(int $offeringId, string $date): ?array
    {
        $query = $this->db->prepare('SELECT id, attendance_date, notes, revision, created_at, updated_at FROM subject_attendance_sessions WHERE faculty_subject_id = :offering AND attendance_date = :date');
        $query->execute(['offering' => $offeringId, 'date' => $date]);
        $row = $query->fetch();
        if (!$row) return null;
        $row['id'] = (int) $row['id']; $row['revision'] = (int) $row['revision']; return $row;
    }

    private function records(int $sessionId): array
    {
        // Use name parts only while they still describe the saved name snapshot.
        $query = $this->db->prepare('SELECT r.student_id, r.student_number, r.student_name, r.status, u.first_name, u.last_name
            FROM subject_attendance_records r LEFT JOIN users u ON u.id = r.student_id AND u.name = r.student_name
            WHERE r.session_id = :session ORDER BY r.student_name, r.student_id');
        $query->execute(['session' => $sessionId]);
        return array_map(static function (array $row): array { $row['student_id'] = (int) $row['student_id']; return $row; }, $query->fetchAll());
    }

    public function sheet(int $facultyId, int $offeringId, string $date = ''): array
    {
        $offering = $this->offering($facultyId, $offeringId);
        $date = $date === '' ? $this->today() : $date; $this->validateDate($date);
        $session = $this->session($offeringId, $date);
        return ['offering' => $offering, 'today' => $this->today(), 'date' => $date, 'session' => $session,
            'students' => $session ? $this->records($session['id']) : $this->roster($offeringId)];
    }

    public function history(int $facultyId, int $offeringId): array
    {
        $offering = $this->offering($facultyId, $offeringId);
        $query = $this->db->prepare("SELECT s.id, s.attendance_date, s.notes, s.revision, s.updated_at,
            COUNT(r.student_id) AS total, COALESCE(SUM(r.status = 'present'), 0) AS present,
            COALESCE(SUM(r.status = 'absent'), 0) AS absent
            FROM subject_attendance_sessions s LEFT JOIN subject_attendance_records r ON r.session_id = s.id
            WHERE s.faculty_subject_id = :offering GROUP BY s.id ORDER BY s.attendance_date DESC");
        $query->execute(['offering' => $offeringId]);
        $sessions = array_map(static function (array $row): array {
            foreach (['id', 'revision', 'total', 'present', 'absent'] as $key) $row[$key] = (int) $row[$key]; return $row;
        }, $query->fetchAll());
        $query = $this->db->prepare('SELECT r.*, s.attendance_date, u.first_name, u.last_name FROM subject_attendance_records r
            INNER JOIN subject_attendance_sessions s ON s.id = r.session_id
            LEFT JOIN users u ON u.id = r.student_id AND u.name = r.student_name
            WHERE s.faculty_subject_id = :offering ORDER BY s.attendance_date');
        $query->execute(['offering' => $offeringId]);
        $students = [];
        foreach ($query->fetchAll() as $record) {
            $id = (int) $record['student_id'];
            if (!isset($students[$id])) $students[$id] = ['student_id' => $id, 'present' => 0, 'absent' => 0, 'recorded' => 0, 'dates' => []];
            $students[$id]['student_name'] = $record['student_name']; $students[$id]['student_number'] = $record['student_number'];
            $students[$id]['first_name'] = $record['first_name']; $students[$id]['last_name'] = $record['last_name'];
            $students[$id][$record['status']]++; $students[$id]['recorded']++;
            $students[$id]['dates'][$record['attendance_date']] = $record['status'];
        }
        foreach ($this->roster($offeringId) as $student) {
            $id = $student['student_id'];
            if (!isset($students[$id])) $students[$id] = ['student_id' => $id, 'student_name' => $student['student_name'],
                'first_name' => $student['first_name'], 'last_name' => $student['last_name'],
                'student_number' => $student['student_number'], 'present' => 0, 'absent' => 0, 'recorded' => 0, 'dates' => []];
        }
        $students = array_values($students);
        foreach ($students as &$student) $student['percentage'] = $student['recorded'] ? round($student['present'] * 100 / $student['recorded'], 2) : null;
        unset($student);
        usort($students, static function (array $a, array $b): int { return strnatcasecmp($a['student_name'], $b['student_name']); });
        return ['offering' => $offering, 'sessions' => $sessions, 'students' => $students];
    }

    public function save(int $facultyId, int $offeringId, array $input): array
    {
        $this->offering($facultyId, $offeringId);
        if (!is_string($input['date'] ?? null)) throw new HttpException(422, 'Choose an attendance date.');
        $date = $input['date']; $this->validateDate($date);
        if (!is_int($input['revision'] ?? null) || $input['revision'] < 0) throw new HttpException(422, 'Reload the attendance sheet before saving.');
        $notes = $input['notes'] ?? '';
        if (!is_string($notes) || mb_strlen($notes) > 500) throw new HttpException(422, 'Class notes must be 500 characters or fewer.');
        $entries = $input['entries'] ?? null;
        if (!is_array($entries) || count($entries) < 1 || count($entries) > 5000) throw new HttpException(422, 'Supply attendance for the students in this class.');
        $statuses = [];
        foreach ($entries as $entry) {
            if (!is_array($entry) || !is_int($entry['student_id'] ?? null) || $entry['student_id'] < 1
                || !in_array($entry['status'] ?? null, ['present', 'absent'], true) || isset($statuses[$entry['student_id']])) {
                throw new HttpException(422, 'Each student must appear once with a valid attendance status.');
            }
            $statuses[$entry['student_id']] = $entry['status'];
        }
        $ownsTransaction = !$this->db->inTransaction();
        if ($ownsTransaction) $this->db->beginTransaction();
        else $this->db->exec('SAVEPOINT attendance_save');
        try {
            // Serialize writes for this class, including two attempts to create the same date.
            $lock = $this->db->prepare('SELECT id FROM faculty_subjects WHERE id = :offering AND faculty_id = :faculty FOR UPDATE');
            $lock->execute(['offering' => $offeringId, 'faculty' => $facultyId]);
            if (!$lock->fetchColumn()) throw new HttpException(404, 'Faculty subject not found.');
            $session = $this->session($offeringId, $date);
            if (($session['revision'] ?? 0) !== $input['revision']) throw new HttpException(409, 'This attendance sheet was saved elsewhere. Reload it before making further changes.');
            $students = $session ? $this->records($session['id']) : $this->roster($offeringId);
            $expected = array_column($students, 'student_id'); $received = array_keys($statuses); sort($expected); sort($received);
            if ($expected !== $received) throw new HttpException(409, 'The class list has changed or contains students outside this sheet. Reload the sheet before saving.');
            if ($session) {
                $sessionId = $session['id'];
                $query = $this->db->prepare('UPDATE subject_attendance_sessions SET notes = :notes, revision = revision + 1, updated_by = :faculty, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
                $query->execute(['notes' => trim($notes), 'faculty' => $facultyId, 'id' => $sessionId]);
                $query = $this->db->prepare('UPDATE subject_attendance_records SET status = :status WHERE session_id = :session AND student_id = :student');
                foreach ($statuses as $id => $status) $query->execute(['status' => $status, 'session' => $sessionId, 'student' => $id]);
            } else {
                $query = $this->db->prepare('INSERT INTO subject_attendance_sessions (faculty_subject_id, attendance_date, notes, created_by, updated_by) VALUES (:offering, :date, :notes, :creator, :editor)');
                $query->execute(['offering' => $offeringId, 'date' => $date, 'notes' => trim($notes), 'creator' => $facultyId, 'editor' => $facultyId]);
                $sessionId = (int) $this->db->lastInsertId();
                $query = $this->db->prepare('INSERT INTO subject_attendance_records (session_id, student_id, student_number, student_name, status) VALUES (:session, :student, :number, :name, :status)');
                foreach ($students as $student) $query->execute(['session' => $sessionId, 'student' => $student['student_id'],
                    'number' => $student['student_number'], 'name' => $student['student_name'], 'status' => $statuses[$student['student_id']]]);
            }
            // Read before releasing the lock so the response contains the revision just saved.
            $result = $this->sheet($facultyId, $offeringId, $date);
            if ($ownsTransaction) $this->db->commit(); else $this->db->exec('RELEASE SAVEPOINT attendance_save');
            return $result;
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->db->inTransaction()) $this->db->rollBack();
            elseif (!$ownsTransaction) $this->db->exec('ROLLBACK TO SAVEPOINT attendance_save');
            throw $exception;
        }
    }
}
