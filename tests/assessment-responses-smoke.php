<?php
declare(strict_types=1);

use Codify\Core\Connection;
use Codify\Repositories\AssessmentBankRepository;

require dirname(__DIR__) . '/bootstrap/autoload.php';

$db = Connection::make();
$candidate = $db->query("SELECT attempt.assessment_bank_id, attempt.faculty_subject_id, attempt.student_id, bank.faculty_id
    FROM student_assessment_attempts attempt
    INNER JOIN assessment_banks bank ON bank.id = attempt.assessment_bank_id
    ORDER BY attempt.id DESC LIMIT 1")->fetch();

if (!$candidate) {
    echo "Assessment responses smoke test skipped: no submissions are available.\n";
    exit(0);
}

$bankId = (int) $candidate['assessment_bank_id'];
$facultyId = (int) $candidate['faculty_id'];
$repository = new AssessmentBankRepository($db);
$banks = $repository->banks($facultyId, ['search' => '', 'bank_type' => '', 'status' => '', 'offering_id' => 0]);
$listed = null;
foreach ($banks as $bank) if ((int) $bank['id'] === $bankId) $listed = $bank;
if ($listed === null || (int) $listed['respondents_count'] < 1 || (int) $listed['submissions_count'] < 1) {
    throw new RuntimeException('Assessment list response counts are missing or invalid.');
}

$responses = $repository->responses($facultyId, $bankId);
if ((int) $responses['summary']['respondents'] < 1 || (int) $responses['summary']['submissions'] < 1) {
    throw new RuntimeException('Assessment response summary is missing submitted work.');
}
$studentFound = false;
foreach ($responses['students'] as $student) {
    if ((int) $student['student_id'] === (int) $candidate['student_id'] && (int) $student['faculty_subject_id'] === (int) $candidate['faculty_subject_id']) {
        $studentFound = true;
        if ((int) $student['attempts_count'] < 1 || (float) $student['final_score_percent'] < 0 || (float) $student['final_score_percent'] > 100) {
            throw new RuntimeException('Assessment student response details are invalid.');
        }
        if (!array_key_exists('average_score', $student) || !array_key_exists('can_grant_retake', $student)) {
            throw new RuntimeException('Assessment final average or retake eligibility is missing.');
        }
        if ($student['can_grant_retake']) {
            $db->beginTransaction();
            try {
                $result = $repository->grantRetakes($facultyId, $bankId, [[
                    'student_id' => (int) $student['student_id'],
                    'faculty_subject_id' => (int) $student['faculty_subject_id'],
                ]]);
                if ((int) $result['granted'] !== 1) throw new RuntimeException('Bulk retake permission was not granted.');
                $after = $repository->responses($facultyId, $bankId);
                foreach ($after['students'] as $updated) {
                    if ((int) $updated['student_id'] === (int) $student['student_id'] && (int) $updated['faculty_subject_id'] === (int) $student['faculty_subject_id'] && (int) $updated['retakes_remaining'] !== 1) {
                        throw new RuntimeException('Bulk retake permission did not expose one additional attempt.');
                    }
                }
                $db->rollBack();
            } catch (Throwable $exception) {
                if ($db->inTransaction()) $db->rollBack();
                throw $exception;
            }
        }
    }
}
if (!$studentFound) throw new RuntimeException('Latest student response was not returned.');

echo "Assessment responses smoke test passed.\n";
