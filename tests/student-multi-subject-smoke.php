<?php
declare(strict_types=1);

use Codify\Core\Connection;
use Codify\Repositories\FacultyTeachingRepository;
use Codify\Repositories\StudentLearningRepository;
use Codify\Repositories\SystemSettingRepository;
use Codify\Repositories\UserRepository;

require dirname(__DIR__) . '/bootstrap/autoload.php';

$db = Connection::make();
$settings = (new SystemSettingRepository($db))->current();
$candidate = $db->prepare("SELECT student.id, student.email, student.faculty_id, profile.student_number,
        offering.id AS offering_id, subject.program_id
    FROM users student
    INNER JOIN student_profiles profile ON profile.user_id = student.id
    INNER JOIN faculty_subject_students enrollment ON enrollment.student_id = student.id
    INNER JOIN faculty_subjects offering ON offering.id = enrollment.faculty_subject_id
        AND offering.faculty_id = student.faculty_id AND offering.is_active = 1
    INNER JOIN subjects subject ON subject.id = offering.subject_id
    WHERE student.role = 'student' AND profile.student_number IS NOT NULL
        AND offering.academic_year = :academic_year AND offering.academic_term = :academic_term
    ORDER BY student.id LIMIT 1");
$candidate->execute(['academic_year' => $settings['academic_year'], 'academic_term' => $settings['academic_term']]);
$student = $candidate->fetch();

if (!$student) {
    echo "Student multi-subject smoke test skipped: no current enrolled student is available.\n";
    exit(0);
}

$users = new UserRepository($db);
$matched = $users->findStudentByNumber((string) $student['student_number']);
if (!$matched || (int) $matched['id'] !== (int) $student['id'] || strcasecmp((string) $matched['email'], (string) $student['email']) !== 0) {
    throw new RuntimeException('The institutional student number did not resolve to the existing account and email.');
}

$other = $db->prepare("SELECT offering.id FROM faculty_subjects offering
    INNER JOIN subjects subject ON subject.id = offering.subject_id
    WHERE offering.faculty_id = :faculty AND subject.program_id = :program
        AND offering.academic_year = :academic_year AND offering.academic_term = :academic_term
        AND offering.is_active = 1 AND offering.id <> :current
        AND NOT EXISTS (SELECT 1 FROM faculty_subject_students enrollment
            WHERE enrollment.faculty_subject_id = offering.id AND enrollment.student_id = :student)
    ORDER BY offering.id LIMIT 1");
$other->execute([
    'faculty' => (int) $student['faculty_id'], 'program' => (int) $student['program_id'],
    'academic_year' => $settings['academic_year'], 'academic_term' => $settings['academic_term'],
    'current' => (int) $student['offering_id'], 'student' => (int) $student['id'],
]);
$otherOffering = (int) ($other->fetchColumn() ?: 0);

if ($otherOffering > 0) {
    $db->beginTransaction();
    try {
        $teaching = new FacultyTeachingRepository($db);
        if (!$teaching->enroll((int) $student['faculty_id'], $otherOffering, (int) $student['id'], 'manual')) {
            throw new RuntimeException('The existing account was not enrolled in the additional subject.');
        }
        $subjects = (new StudentLearningRepository($db))->subjects(
            (int) $student['id'], (string) $settings['academic_year'], (string) $settings['academic_term']
        );
        $subjectIds = array_map(static function (array $subject): int { return (int) $subject['id']; }, $subjects);
        if (!in_array((int) $student['offering_id'], $subjectIds, true) || !in_array($otherOffering, $subjectIds, true)) {
            throw new RuntimeException('The single student dashboard did not return both subject enrollments.');
        }
        $emailAfterEnrollment = $users->find((int) $student['id']);
        if (!$emailAfterEnrollment || strcasecmp((string) $emailAfterEnrollment['email'], (string) $student['email']) !== 0) {
            throw new RuntimeException('Adding another subject changed the existing student email.');
        }
        $db->rollBack();
    } catch (Throwable $exception) {
        if ($db->inTransaction()) $db->rollBack();
        throw $exception;
    }
}

echo "Student multi-subject account reuse smoke test passed.\n";
