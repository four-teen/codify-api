<?php
declare(strict_types=1);

use Codify\Core\Connection;
use Codify\Core\HttpException;
use Codify\Repositories\SubjectGradebookRepository;

require dirname(__DIR__) . '/bootstrap/autoload.php';

$db = Connection::make();
$candidate = $db->query("SELECT subject.id AS offering_id, subject.faculty_id, enrollment.student_id
    FROM faculty_subjects subject
    INNER JOIN faculty_subject_students enrollment ON enrollment.faculty_subject_id = subject.id
    WHERE subject.is_active = 1
    ORDER BY subject.id LIMIT 1")->fetch();

if (!$candidate) {
    echo "Subject gradebook smoke test skipped: no active subject with an enrolled student is available.\n";
    exit(0);
}

$offeringId = (int) $candidate['offering_id'];
$facultyId = (int) $candidate['faculty_id'];
$studentId = (int) $candidate['student_id'];
$repository = new SubjectGradebookRepository($db);
$attemptsBefore = $db->query('SELECT COUNT(*) FROM student_assessment_attempts')->fetchColumn();

$db->beginTransaction();
try {
    $gradebook = $repository->gradebook($facultyId, $offeringId);
    if (count($gradebook['categories']) !== 10) throw new RuntimeException('The gradebook did not create five categories for each term.');

    $totals = ['midterm' => 0.0, 'final' => 0.0];
    foreach ($gradebook['categories'] as $category) $totals[$category['grading_period']] += (float) $category['weight'];
    foreach ($totals as $period => $total) if (abs($total - 100.0) > 0.01) throw new RuntimeException(ucfirst($period) . ' weights do not total 100%.');

    if ($gradebook['settings']['midterm_status'] === 'locked' || $gradebook['settings']['final_status'] === 'locked') {
        $weights = [];
        foreach ($gradebook['categories'] as $category) $weights[(int) $category['id']] = (float) $category['weight'];
        $gradebook = $repository->updateSettings($facultyId, $offeringId, $weights, 'draft', 'draft');
    }

    $assertAutomaticReadOnly = static function (array $item) use ($repository, $facultyId, $offeringId, $studentId): void {
        if ($item['source_type'] !== 'assessment') return;
        try {
            $repository->saveScores($facultyId, $offeringId, [[
                'item_id' => (int) $item['id'], 'student_id' => $studentId,
                'status' => 'graded', 'score' => 0.0, 'remarks' => null,
            ]]);
        } catch (HttpException $exception) {
            if ($exception->status === 422) return;
            throw $exception;
        }
        throw new RuntimeException('An automatic quiz or exam score accepted a faculty override.');
    };

    $linked = null;
    foreach ($gradebook['items'] as $item) {
        if ($item['source_type'] === 'assessment') { $linked = $item; break; }
    }
    if ($linked === null) {
        foreach ($gradebook['items'] as $item) {
            if ($item['source_type'] === 'problem') { $linked = $item; break; }
        }
    }
    if ($linked !== null) {
        $gradebook = $repository->deleteItem($facultyId, $offeringId, (int) $linked['id']);
        foreach ($gradebook['items'] as $item) if ((int) $item['id'] === (int) $linked['id']) throw new RuntimeException('A removed linked activity remained in the gradebook.');
        $available = null;
        foreach ($gradebook['available_activities'] as $activity) {
            if ($activity['source_type'] === $linked['source_type'] && (int) $activity['source_id'] === (int) $linked['source_id']) { $available = $activity; break; }
        }
        if ($available === null) throw new RuntimeException('A removed linked activity did not return to the available activity selector.');
        $gradebook = $repository->createLinkedItem($facultyId, $offeringId, [
            'source_type' => $linked['source_type'], 'source_id' => (int) $linked['source_id'], 'category_id' => (int) $linked['category_id'],
            'counts_toward_grade' => (bool) $linked['counts_toward_grade'], 'due_at' => $linked['due_at'],
        ]);
        $restored = null;
        foreach ($gradebook['items'] as $item) if ($item['source_type'] === $linked['source_type'] && (int) $item['source_id'] === (int) $linked['source_id']) $restored = $item;
        if ($restored === null) throw new RuntimeException('Selecting an existing activity did not add it back to the gradebook.');
        $assertAutomaticReadOnly($restored);
    } elseif ($gradebook['available_activities'] !== []) {
        $available = null;
        foreach ($gradebook['available_activities'] as $activity) {
            if ($activity['source_type'] === 'assessment') { $available = $activity; break; }
        }
        if ($available === null) $available = $gradebook['available_activities'][0];
        $targetCategory = null;
        $targetKey = $available['activity_type'] === 'exam' ? 'examination' : ($available['activity_type'] === 'coding' ? 'written_activities' : 'quizzes');
        foreach ($gradebook['categories'] as $item) if ($item['grading_period'] === 'midterm' && $item['category_key'] === $targetKey) $targetCategory = $item;
        if ($targetCategory === null) throw new RuntimeException('A default category for the available activity is missing.');
        $gradebook = $repository->createLinkedItem($facultyId, $offeringId, [
            'source_type' => $available['source_type'], 'source_id' => (int) $available['source_id'], 'category_id' => (int) $targetCategory['id'],
            'counts_toward_grade' => true, 'due_at' => null,
        ]);
        $selected = null;
        foreach ($gradebook['items'] as $item) if ($item['source_type'] === $available['source_type'] && (int) $item['source_id'] === (int) $available['source_id']) $selected = $item;
        if ($selected === null) throw new RuntimeException('An available existing activity could not be selected for grading.');
        $assertAutomaticReadOnly($selected);
        $gradebook = $repository->deleteItem($facultyId, $offeringId, (int) $selected['id']);
        $returned = false;
        foreach ($gradebook['available_activities'] as $activity) if ($activity['source_type'] === $available['source_type'] && (int) $activity['source_id'] === (int) $available['source_id']) $returned = true;
        if (!$returned) throw new RuntimeException('A removed activity did not return to the selector.');
    }

    $category = null;
    foreach ($gradebook['categories'] as $item) {
        if ($item['grading_period'] === 'final' && $item['category_key'] === 'quizzes') { $category = $item; break; }
    }
    if ($category === null) throw new RuntimeException('The Final Term quizzes category is missing.');

    $gradebook = $repository->createManualItem($facultyId, $offeringId, [
        'title' => 'Gradebook smoke activity', 'category_id' => (int) $category['id'],
        'max_points' => 100.0, 'counts_toward_grade' => true, 'due_at' => null,
    ]);
    $created = null;
    foreach ($gradebook['items'] as $item) if ($item['title'] === 'Gradebook smoke activity') $created = $item;
    if ($created === null || $created['grading_period'] !== 'final') throw new RuntimeException('Manual grade item creation failed.');

    $gradebook = $repository->saveScores($facultyId, $offeringId, [[
        'item_id' => (int) $created['id'], 'student_id' => $studentId,
        'status' => 'missing', 'score' => 0.0, 'remarks' => null,
    ]]);
    $student = null;
    foreach ($gradebook['students'] as $row) if ((int) $row['id'] === $studentId) $student = $row;
    if ($student === null) throw new RuntimeException('Enrolled student is missing from the gradebook.');
    $calculation = null;
    foreach ($student['periods']['final']['categories'] as $row) if ((int) $row['category_id'] === (int) $category['id']) $calculation = $row;
    $expectedMissingContribution = round(40 * ((float) $category['weight'] / 100), 2);
    if ($calculation === null || abs((float) $calculation['transmuted_percentage'] - 40.0) > 0.01 || abs((float) $calculation['weighted_contribution'] - $expectedMissingContribution) > 0.01) {
        throw new RuntimeException('A zero or missing score did not produce the expected Base-40 contribution.');
    }

    $gradebook = $repository->saveScores($facultyId, $offeringId, [[
        'item_id' => (int) $created['id'], 'student_id' => $studentId,
        'status' => 'graded', 'score' => 100.0, 'remarks' => null,
    ]]);
    foreach ($gradebook['students'] as $row) if ((int) $row['id'] === $studentId) $student = $row;
    foreach ($student['periods']['final']['categories'] as $row) if ((int) $row['category_id'] === (int) $category['id']) $calculation = $row;
    if (abs((float) $calculation['transmuted_percentage'] - 100.0) > 0.01 || abs((float) $calculation['weighted_contribution'] - (float) $category['weight']) > 0.01) {
        throw new RuntimeException('A perfect score did not produce the expected weighted contribution.');
    }

    if ((int) $db->query('SELECT COUNT(*) FROM student_assessment_attempts')->fetchColumn() !== (int) $attemptsBefore) {
        throw new RuntimeException('Opening or editing the gradebook changed quiz attempt records.');
    }
    $db->rollBack();
} catch (Throwable $exception) {
    if ($db->inTransaction()) $db->rollBack();
    throw $exception;
}

echo "Subject gradebook smoke test passed.\n";
