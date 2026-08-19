<?php
declare(strict_types=1);

use Codify\Core\Connection;
use Codify\Core\HttpException;
use Codify\Repositories\FacultyTeachingRepository;
use Codify\Repositories\StudentLearningRepository;
use Codify\Repositories\SystemSettingRepository;

require dirname(__DIR__) . '/bootstrap/autoload.php';

$db = Connection::make();
$candidate = $db->query("SELECT attempt.assessment_bank_id, attempt.faculty_subject_id, attempt.student_id, subject.faculty_id
    FROM student_assessment_attempts attempt
    INNER JOIN assessment_banks bank ON bank.id = attempt.assessment_bank_id AND bank.is_active = 1
    INNER JOIN faculty_subjects subject ON subject.id = attempt.faculty_subject_id AND subject.is_active = 1
    INNER JOIN assessment_bank_subjects link ON link.assessment_bank_id = bank.id AND link.faculty_subject_id = subject.id
    INNER JOIN faculty_subject_students enrollment ON enrollment.faculty_subject_id = subject.id AND enrollment.student_id = attempt.student_id
    ORDER BY attempt.id DESC LIMIT 1")->fetch();

if (!$candidate) {
    echo "Assessment retake smoke test skipped: no submitted active assessment is available.\n";
    exit(0);
}

$assessmentId = (int) $candidate['assessment_bank_id'];
$offeringId = (int) $candidate['faculty_subject_id'];
$studentId = (int) $candidate['student_id'];
$facultyId = (int) $candidate['faculty_id'];
$settings = (new SystemSettingRepository($db))->current();
$learning = new StudentLearningRepository($db);
$assessments = $learning->assessments($studentId, $offeringId, $settings['academic_year'], $settings['academic_term']);
$assessment = null;
foreach ($assessments as $item) if ((int) $item['id'] === $assessmentId) $assessment = $item;
if ($assessment === null) throw new RuntimeException('Submitted assessment was missing from the student subject dashboard.');

$permission = $db->prepare('SELECT additional_attempts FROM student_assessment_retake_permissions WHERE assessment_bank_id = :assessment AND faculty_subject_id = :offering AND student_id = :student');
$permission->execute(['assessment' => $assessmentId, 'offering' => $offeringId, 'student' => $studentId]);
$additionalAttempts = (int) ($permission->fetchColumn() ?: 0);
$expectedAvailability = (int) $assessment['attempts_count'] < 1 + $additionalAttempts;
if ((bool) $assessment['can_attempt'] !== $expectedAvailability) throw new RuntimeException('Student assessment availability is inconsistent with attempts and permissions.');
if (!array_key_exists('final_score_percent', $assessment)) throw new RuntimeException('Student final average is missing.');

try {
    $learning->assessment($studentId, $offeringId, $assessmentId, $settings['academic_year'], $settings['academic_term']);
    if (!$assessment['can_attempt']) throw new RuntimeException('A locked assessment exposed its questions.');
} catch (HttpException $exception) {
    if ($assessment['can_attempt'] || $exception->status !== 409) throw $exception;
}
if (!$assessment['can_attempt']) {
    try {
        $learning->submitAssessment($studentId, $offeringId, $assessmentId, $settings['academic_year'], $settings['academic_term'], []);
        throw new RuntimeException('A second assessment submission bypassed the attempt limit.');
    } catch (HttpException $exception) {
        if ($exception->status !== 409) throw $exception;
    }
}

$teaching = new FacultyTeachingRepository($db);
$retakes = $teaching->studentAssessmentRetakes($facultyId, $offeringId, $studentId);
$facultyQuiz = null;
foreach ($retakes as $item) if ((int) $item['id'] === $assessmentId) $facultyQuiz = $item;
if ($facultyQuiz === null) throw new RuntimeException('Submitted assessment was missing from faculty retake management.');
if ((bool) $facultyQuiz['can_grant_retake'] !== ((int) $facultyQuiz['attempts_count'] > 0 && (int) $facultyQuiz['retakes_remaining'] === 0)) {
    throw new RuntimeException('Faculty assessment retake eligibility is inconsistent.');
}

if ($facultyQuiz['can_grant_retake']) {
    $db->beginTransaction();
    try {
        $granted = $teaching->grantStudentAssessmentRetake($facultyId, $offeringId, $studentId, $assessmentId);
        if ((int) $granted['retakes_remaining'] !== 1 || $granted['can_grant_retake']) {
            throw new RuntimeException('Granting a retake did not create exactly one available submission.');
        }
        $available = $learning->assessments($studentId, $offeringId, $settings['academic_year'], $settings['academic_term']);
        foreach ($available as $item) {
            if ((int) $item['id'] === $assessmentId && !$item['can_attempt']) {
                throw new RuntimeException('A faculty retake grant did not unlock the assessment for the student.');
            }
        }
        $db->rollBack();
    } catch (Throwable $exception) {
        if ($db->inTransaction()) $db->rollBack();
        throw $exception;
    }
}

echo "Assessment retake smoke test passed.\n";
