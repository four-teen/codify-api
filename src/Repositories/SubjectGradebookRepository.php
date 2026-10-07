<?php
declare(strict_types=1);

namespace Codify\Repositories;

use Codify\Core\HttpException;
use PDO;
use Throwable;

final class SubjectGradebookRepository
{
    /** @var PDO */
    private $db;

    private const DEFAULT_CATEGORIES = [
        ['midterm', 'quizzes', 'Quizzes', 25.00, 1],
        ['midterm', 'written_activities', 'Written Activities / Exercises', 20.00, 2],
        ['midterm', 'case_analysis', 'Case Analysis / Assignments', 15.00, 3],
        ['midterm', 'participation', 'Class Participation / Presentation', 10.00, 4],
        ['midterm', 'examination', 'Midterm Examination', 30.00, 5],
        ['final', 'quizzes', 'Quizzes', 20.00, 1],
        ['final', 'written_activities', 'Written Activities / Exercises', 20.00, 2],
        ['final', 'case_analysis', 'Case Analysis / Assignments', 15.00, 3],
        ['final', 'participation', 'Presentation / Integrative Activity', 15.00, 4],
        ['final', 'examination', 'Final Examination', 30.00, 5],
    ];

    public function __construct(PDO $db) { $this->db = $db; }

    public function gradebook(int $facultyId, int $offeringId): array
    {
        return $this->transaction(function () use ($facultyId, $offeringId): array {
            $offering = $this->offering($facultyId, $offeringId);
            $this->ensureDefaults($offeringId, $facultyId);
            $this->refreshSelectedItems($offeringId);
            return $this->snapshot($facultyId, $offering);
        });
    }

    public function updateSettings(int $facultyId, int $offeringId, array $weights, string $midtermStatus, string $finalStatus): array
    {
        $this->transaction(function () use ($facultyId, $offeringId, $weights, $midtermStatus, $finalStatus): void {
            $offering = $this->offering($facultyId, $offeringId); $this->ensureDefaults($offeringId, $facultyId); $this->refreshSelectedItems($offeringId);
            $settings = $this->settings($offeringId); $categories = $this->categories($offeringId);
            $categoryMap = []; foreach ($categories as $category) $categoryMap[(int) $category['id']] = $category;
            $requestedStatus = ['midterm' => $midtermStatus, 'final' => $finalStatus];
            foreach ($weights as $id => $weight) {
                if (!isset($categoryMap[$id])) throw new HttpException(422, 'One or more grading categories are invalid.');
                $period = $categoryMap[$id]['grading_period'];
                if ($settings[$period . '_status'] === 'locked' && $requestedStatus[$period] === 'locked' && abs((float) $categoryMap[$id]['weight'] - $weight) > 0.001) {
                    throw new HttpException(422, ucfirst($period) . ' grading is locked. Unlock it before changing category weights.');
                }
                $categoryMap[$id]['weight'] = $weight;
            }
            foreach (['midterm', 'final'] as $period) {
                $total = 0.0; foreach ($categoryMap as $category) if ($category['grading_period'] === $period) $total += (float) $category['weight'];
                if (abs($total - 100.0) > 0.01) throw new HttpException(422, ucfirst($period) . ' category weights must total exactly 100%.');
            }
            $updateCategory = $this->db->prepare('UPDATE subject_grade_categories SET weight = :weight, updated_at = NOW() WHERE id = :id AND faculty_subject_id = :offering');
            foreach ($weights as $id => $weight) $updateCategory->execute(['weight' => $weight, 'id' => $id, 'offering' => $offeringId]);
            $preview = $this->snapshot($facultyId, $offering);
            foreach (['midterm' => $midtermStatus, 'final' => $finalStatus] as $period => $status) {
                if ($status !== 'locked') continue;
                if ($preview['students'] === []) throw new HttpException(422, ucfirst($period) . ' cannot be locked without enrolled students.');
                foreach ($preview['students'] as $student) if (!$student['periods'][$period]['complete']) throw new HttpException(422, ucfirst($period) . ' cannot be locked while student grades are incomplete.');
            }
            $this->db->prepare('UPDATE subject_grading_settings SET midterm_status = :midterm, final_status = :final, updated_by = :faculty, updated_at = NOW() WHERE faculty_subject_id = :offering')
                ->execute(['midterm' => $midtermStatus, 'final' => $finalStatus, 'faculty' => $facultyId, 'offering' => $offeringId]);
            $this->audit($offeringId, null, null, $facultyId, 'grading_settings_updated', $settings, ['midterm_status' => $midtermStatus, 'final_status' => $finalStatus, 'weights' => $weights]);
        });
        return $this->gradebook($facultyId, $offeringId);
    }

    public function createManualItem(int $facultyId, int $offeringId, array $data): array
    {
        $this->transaction(function () use ($facultyId, $offeringId, $data): void {
            $this->offering($facultyId, $offeringId); $this->ensureDefaults($offeringId, $facultyId);
            $category = $this->category($offeringId, (int) $data['category_id']); $this->assertUnlocked($offeringId, $category['grading_period']);
            $position = $this->nextItemPosition($offeringId, (int) $category['id']);
            $statement = $this->db->prepare("INSERT INTO subject_grade_items
                (faculty_subject_id, category_id, source_type, title, max_points, counts_toward_grade, due_at, position, created_at, updated_at)
                VALUES (:offering, :category, 'manual', :title, :max_points, :included, :due_at, :position, NOW(), NOW())");
            $statement->execute(['offering' => $offeringId, 'category' => $category['id'], 'title' => $data['title'], 'max_points' => $data['max_points'], 'included' => $data['counts_toward_grade'] ? 1 : 0, 'due_at' => $data['due_at'], 'position' => $position]);
            $itemId = (int) $this->db->lastInsertId();
            $this->audit($offeringId, $itemId, null, $facultyId, 'grade_item_created', null, $data);
        });
        return $this->gradebook($facultyId, $offeringId);
    }

    public function createLinkedItem(int $facultyId, int $offeringId, array $data): array
    {
        $this->transaction(function () use ($facultyId, $offeringId, $data): void {
            $this->offering($facultyId, $offeringId); $this->ensureDefaults($offeringId, $facultyId);
            $category = $this->category($offeringId, (int) $data['category_id']); $this->assertUnlocked($offeringId, $category['grading_period']);
            $sourceType = $data['source_type']; $sourceId = (int) $data['source_id'];
            $activity = $this->linkedActivity($offeringId, $sourceType, $sourceId);
            $column = $sourceType === 'assessment' ? 'assessment_bank_id' : 'coding_problem_id';
            $duplicate = $this->db->prepare("SELECT id FROM subject_grade_items WHERE faculty_subject_id = :offering AND {$column} = :source LIMIT 1");
            $duplicate->execute(['offering' => $offeringId, 'source' => $sourceId]);
            if ($duplicate->fetchColumn()) throw new HttpException(409, 'This activity is already included in the gradebook.');
            $position = $this->nextItemPosition($offeringId, (int) $category['id']);
            $statement = $this->db->prepare("INSERT INTO subject_grade_items
                (faculty_subject_id, category_id, source_type, {$column}, title, max_points, counts_toward_grade, due_at, position, created_at, updated_at)
                VALUES (:offering, :category, :source_type, :source, :title, :max_points, :included, :due_at, :position, NOW(), NOW())");
            $statement->execute([
                'offering' => $offeringId, 'category' => $category['id'], 'source_type' => $sourceType, 'source' => $sourceId,
                'title' => $activity['title'], 'max_points' => $activity['max_points'], 'included' => $data['counts_toward_grade'] ? 1 : 0,
                'due_at' => $data['due_at'], 'position' => $position,
            ]);
            $itemId = (int) $this->db->lastInsertId();
            $this->audit($offeringId, $itemId, null, $facultyId, 'linked_activity_added', null, $data + ['title' => $activity['title']]);
        });
        return $this->gradebook($facultyId, $offeringId);
    }

    public function updateItem(int $facultyId, int $offeringId, int $itemId, array $data): array
    {
        $this->transaction(function () use ($facultyId, $offeringId, $itemId, $data): void {
            $this->offering($facultyId, $offeringId); $this->ensureDefaults($offeringId, $facultyId);
            $item = $this->item($offeringId, $itemId); $category = $this->category($offeringId, (int) $data['category_id']);
            $this->assertUnlocked($offeringId, $item['grading_period']); $this->assertUnlocked($offeringId, $category['grading_period']);
            $title = $item['source_type'] === 'manual' ? $data['title'] : $item['title'];
            $maxPoints = $item['source_type'] === 'manual' ? $data['max_points'] : (float) $item['max_points'];
            if ($item['source_type'] === 'manual') {
                $maximum = $this->db->prepare('SELECT MAX(score) FROM student_grade_entries WHERE grade_item_id = :item'); $maximum->execute(['item' => $itemId]);
                $highest = $maximum->fetchColumn();
                if ($highest !== null && (float) $highest > $maxPoints) throw new HttpException(422, 'Maximum points cannot be lower than an existing student score.');
            }
            $statement = $this->db->prepare('UPDATE subject_grade_items SET category_id = :category, title = :title, max_points = :max_points, counts_toward_grade = :included, due_at = :due_at, updated_at = NOW() WHERE id = :id AND faculty_subject_id = :offering');
            $statement->execute(['category' => $category['id'], 'title' => $title, 'max_points' => $maxPoints, 'included' => $data['counts_toward_grade'] ? 1 : 0, 'due_at' => $data['due_at'], 'id' => $itemId, 'offering' => $offeringId]);
            $this->audit($offeringId, $itemId, null, $facultyId, 'grade_item_updated', $item, $data);
        });
        return $this->gradebook($facultyId, $offeringId);
    }

    public function deleteItem(int $facultyId, int $offeringId, int $itemId): array
    {
        $this->transaction(function () use ($facultyId, $offeringId, $itemId): void {
            $this->offering($facultyId, $offeringId); $item = $this->item($offeringId, $itemId);
            $this->assertUnlocked($offeringId, $item['grading_period']);
            $this->audit($offeringId, $itemId, null, $facultyId, 'grade_item_removed', $item, null);
            $this->db->prepare('DELETE FROM subject_grade_items WHERE id = :id AND faculty_subject_id = :offering')->execute(['id' => $itemId, 'offering' => $offeringId]);
        });
        return $this->gradebook($facultyId, $offeringId);
    }

    public function saveScores(int $facultyId, int $offeringId, array $entries): array
    {
        $this->transaction(function () use ($facultyId, $offeringId, $entries): void {
            $this->offering($facultyId, $offeringId); $this->ensureDefaults($offeringId, $facultyId);
            $items = []; foreach ($this->items($offeringId) as $item) $items[(int) $item['id']] = $item;
            $students = []; foreach ($this->students($offeringId) as $student) $students[(int) $student['id']] = $student;
            $select = $this->db->prepare('SELECT score, status, remarks FROM student_grade_entries WHERE grade_item_id = :item AND student_id = :student LIMIT 1');
            $upsert = $this->db->prepare("INSERT INTO student_grade_entries (grade_item_id, student_id, score, status, remarks, graded_by, graded_at, updated_at)
                VALUES (:item, :student, :score, :status, :remarks, :faculty, NOW(), NOW())
                ON DUPLICATE KEY UPDATE score = VALUES(score), status = VALUES(status), remarks = VALUES(remarks), graded_by = VALUES(graded_by), graded_at = NOW(), updated_at = NOW()");
            $delete = $this->db->prepare('DELETE FROM student_grade_entries WHERE grade_item_id = :item AND student_id = :student');
            foreach ($entries as $entry) {
                $itemId = (int) $entry['item_id']; $studentId = (int) $entry['student_id'];
                if (!isset($items[$itemId]) || !isset($students[$studentId])) throw new HttpException(422, 'One or more grade entries do not belong to this subject.');
                $item = $items[$itemId]; $this->assertUnlocked($offeringId, $item['grading_period']);
                if ($item['source_type'] === 'assessment') {
                    throw new HttpException(422, 'Quiz and exam scores are calculated automatically and cannot be changed in the gradebook.');
                }
                if ($item['source_type'] === 'problem' && $item['problem_rubric_id'] !== null && $entry['status'] !== 'ungraded') {
                    throw new HttpException(422, 'Python problem scores come from the finalized rubric and cannot be changed in the gradebook.');
                }
                $select->execute(['item' => $itemId, 'student' => $studentId]); $previous = $select->fetch() ?: null;
                if ($entry['status'] === 'ungraded') {
                    $delete->execute(['item' => $itemId, 'student' => $studentId]);
                } else {
                    $score = $entry['status'] === 'missing' ? 0.0 : $entry['score'];
                    if ($entry['status'] === 'graded' && ($score === null || $score < 0 || $score > (float) $item['max_points'])) throw new HttpException(422, 'A score is outside the grade item maximum.');
                    if (in_array($entry['status'], ['excused', 'pending'], true)) $score = null;
                    $upsert->execute(['item' => $itemId, 'student' => $studentId, 'score' => $score, 'status' => $entry['status'], 'remarks' => $entry['remarks'], 'faculty' => $facultyId]);
                }
                $this->audit($offeringId, $itemId, $studentId, $facultyId, 'student_grade_updated', $previous, $entry);
            }
        });
        return $this->gradebook($facultyId, $offeringId);
    }

    private function snapshot(int $facultyId, array $offering): array
    {
        $offeringId = (int) $offering['id']; $settings = $this->settings($offeringId); $categories = $this->categories($offeringId); $items = $this->items($offeringId); $students = $this->students($offeringId);
        $entries = []; $statement = $this->db->prepare("SELECT entry.* FROM student_grade_entries entry INNER JOIN subject_grade_items item ON item.id = entry.grade_item_id WHERE item.faculty_subject_id = :offering");
        $statement->execute(['offering' => $offeringId]); foreach ($statement->fetchAll() as $entry) $entries[(int) $entry['grade_item_id']][(int) $entry['student_id']] = $entry;
        $automatic = $this->assessmentScores($offeringId);
        $rubricScores = $this->problemScores($offeringId);
        $categoryItems = []; foreach ($items as $item) $categoryItems[(int) $item['category_id']][] = $item;
        $studentRows = []; $periodTotals = ['midterm' => [], 'final' => []]; $finalTotals = [];
        foreach ($students as $student) {
            $studentId = (int) $student['id']; $scores = [];
            foreach ($items as $item) {
                $itemId = (int) $item['id']; $entry = $entries[$itemId][$studentId] ?? null;
                if ($item['source_type'] === 'assessment') {
                    if (isset($automatic[$itemId][$studentId])) {
                        $auto = $automatic[$itemId][$studentId];
                        $scores[$itemId] = ['score' => round(((float) $auto['percent'] / 100) * (float) $item['max_points'], 2), 'status' => $auto['pending'] ? 'pending' : 'graded', 'source' => 'automatic', 'remarks' => $auto['pending'] ? 'Written responses still need faculty review.' : null];
                    } else {
                        $scores[$itemId] = ['score' => null, 'status' => 'ungraded', 'source' => 'none', 'remarks' => null];
                    }
                } elseif ($item['source_type'] === 'problem' && $item['problem_rubric_id'] !== null) {
                    $scores[$itemId] = $rubricScores[$itemId][$studentId] ?? ['score' => null, 'status' => 'ungraded', 'source' => 'none', 'remarks' => null];
                } elseif ($entry) {
                    $scores[$itemId] = ['score' => $entry['score'] === null ? null : round((float) $entry['score'], 2), 'status' => $entry['status'], 'source' => 'manual', 'remarks' => $entry['remarks']];
                } else {
                    $scores[$itemId] = ['score' => null, 'status' => 'ungraded', 'source' => 'none', 'remarks' => null];
                }
            }
            $periods = [];
            foreach (['midterm', 'final'] as $period) {
                $periods[$period] = $this->periodGrade($period, $categories, $categoryItems, $scores, (float) $settings['base_grade'], (float) $settings['transmutation_span']);
                $periodTotals[$period][] = $periods[$period]['score'];
            }
            $finalScore = round(($periods['midterm']['score'] + $periods['final']['score']) / 2, 2);
            $finalComplete = $periods['midterm']['complete'] && $periods['final']['complete']; $finalTotals[] = $finalScore;
            $studentRows[] = [
                'id' => $studentId, 'student_number' => $student['student_number'] ?: $student['username'], 'name' => $student['name'],
                'scores' => $scores, 'periods' => $periods,
                'final_grade' => ['score' => $finalScore, 'complete' => $finalComplete] + $this->legend($finalScore),
            ];
        }
        $categoryPayload = []; foreach ($categories as $category) $categoryPayload[] = $this->categoryPayload($category, count($categoryItems[(int) $category['id']] ?? []));
        $itemPayload = []; foreach ($items as $item) $itemPayload[] = $this->itemPayload($item);
        $audit = $this->recentAudit($offeringId);
        return [
            'offering' => $offering,
            'settings' => ['base_grade' => (float) $settings['base_grade'], 'transmutation_span' => (float) $settings['transmutation_span'], 'midterm_status' => $settings['midterm_status'], 'final_status' => $settings['final_status'], 'final_formula' => '(Midterm + Final) / 2'],
            'categories' => $categoryPayload, 'items' => $itemPayload, 'available_activities' => $this->availableActivities($offeringId), 'students' => $studentRows,
            'summary' => [
                'students_count' => count($students), 'items_count' => count($items),
                'midterm_average' => $this->average($periodTotals['midterm']), 'final_average' => $this->average($periodTotals['final']), 'overall_average' => $this->average($finalTotals),
            ],
            'legend' => $this->legendRows(), 'audit' => $audit,
        ];
    }

    private function periodGrade(string $period, array $categories, array $categoryItems, array $scores, float $base, float $span): array
    {
        $complete = true; $score = 0.0; $categoryGrades = [];
        foreach ($categories as $category) {
            if ($category['grading_period'] !== $period) continue;
            $earned = 0.0; $possible = 0.0; $includedCount = 0; $pendingCount = 0;
            foreach ($categoryItems[(int) $category['id']] ?? [] as $item) {
                if (!(bool) $item['counts_toward_grade']) continue;
                $includedCount++; $cell = $scores[(int) $item['id']];
                if ($cell['status'] === 'excused') continue;
                if (!in_array($cell['status'], ['graded', 'missing'], true)) { $complete = false; $pendingCount++; continue; }
                $possible += (float) $item['max_points']; $earned += (float) ($cell['score'] ?? 0);
            }
            if ($includedCount < 1 || $possible <= 0) $complete = false;
            $raw = $possible > 0 ? ($earned / $possible) * 100 : 0.0;
            $transmuted = $possible > 0 ? $base + (($raw / 100) * $span) : 0.0;
            $contribution = $transmuted * ((float) $category['weight'] / 100); $score += $contribution;
            $categoryGrades[] = ['category_id' => (int) $category['id'], 'earned' => round($earned, 2), 'possible' => round($possible, 2), 'raw_percentage' => round($raw, 2), 'transmuted_percentage' => round($transmuted, 2), 'weighted_contribution' => round($contribution, 2), 'included_items' => $includedCount, 'pending_items' => $pendingCount];
        }
        $score = round($score, 2);
        return ['score' => $score, 'complete' => $complete, 'categories' => $categoryGrades] + $this->legend($score);
    }

    private function assessmentScores(int $offeringId): array
    {
        $statement = $this->db->prepare("SELECT item.id AS grade_item_id, grouped.student_id, grouped.final_score_percent,
            latest.grading_status AS latest_grading_status
            FROM subject_grade_items item
            INNER JOIN (
                SELECT assessment_bank_id, student_id, MAX(id) AS latest_id,
                    AVG(CASE WHEN total_points > 0 THEN (auto_score / total_points) * 100 ELSE 0 END) AS final_score_percent
                FROM student_assessment_attempts WHERE faculty_subject_id = :attempt_offering GROUP BY assessment_bank_id, student_id
            ) grouped ON grouped.assessment_bank_id = item.assessment_bank_id
            INNER JOIN student_assessment_attempts latest ON latest.id = grouped.latest_id
            WHERE item.faculty_subject_id = :item_offering AND item.source_type = 'assessment'");
        $statement->execute(['attempt_offering' => $offeringId, 'item_offering' => $offeringId]); $scores = [];
        foreach ($statement->fetchAll() as $row) $scores[(int) $row['grade_item_id']][(int) $row['student_id']] = ['percent' => round((float) $row['final_score_percent'], 2), 'pending' => $row['latest_grading_status'] === 'pending_review'];
        return $scores;
    }

    private function problemScores(int $offeringId): array
    {
        $statement = $this->db->prepare("SELECT item.id AS grade_item_id, work.student_id, evaluation.final_score
            FROM subject_grade_items item
            INNER JOIN coding_problem_work work ON work.problem_id = item.coding_problem_id AND work.status = 'submitted'
            LEFT JOIN coding_problem_evaluations evaluation ON evaluation.problem_id = work.problem_id AND evaluation.student_id = work.student_id
            WHERE item.faculty_subject_id = :offering AND item.source_type = 'problem'");
        $statement->execute(['offering' => $offeringId]); $scores = [];
        foreach ($statement->fetchAll() as $row) {
            $finalized = $row['final_score'] !== null;
            $scores[(int) $row['grade_item_id']][(int) $row['student_id']] = [
                'score' => $finalized ? round((float) $row['final_score'], 2) : null,
                'status' => $finalized ? 'graded' : 'pending',
                'source' => 'rubric',
                'remarks' => null,
            ];
        }
        return $scores;
    }

    private function ensureDefaults(int $offeringId, int $facultyId): void
    {
        $this->db->prepare('INSERT IGNORE INTO subject_grading_settings (faculty_subject_id, base_grade, transmutation_span, midterm_status, final_status, updated_by, created_at, updated_at) VALUES (:offering, 40, 60, \'draft\', \'draft\', :faculty, NOW(), NOW())')->execute(['offering' => $offeringId, 'faculty' => $facultyId]);
        $statement = $this->db->prepare('INSERT IGNORE INTO subject_grade_categories (faculty_subject_id, grading_period, category_key, name, weight, position, created_at, updated_at) VALUES (:offering, :period, :category_key, :name, :weight, :position, NOW(), NOW())');
        foreach (self::DEFAULT_CATEGORIES as $category) $statement->execute(['offering' => $offeringId, 'period' => $category[0], 'category_key' => $category[1], 'name' => $category[2], 'weight' => $category[3], 'position' => $category[4]]);
    }

    private function refreshSelectedItems(int $offeringId): void
    {
        $assessment = $this->db->prepare("UPDATE subject_grade_items item INNER JOIN assessment_banks bank ON bank.id = item.assessment_bank_id
            SET item.title = CONCAT(bank.code, ' - ', bank.title),
                item.max_points = GREATEST(1, COALESCE((SELECT SUM(question.points) FROM assessment_bank_questions question WHERE question.assessment_bank_id = bank.id), 0)),
                item.updated_at = NOW()
            WHERE item.faculty_subject_id = :offering AND item.source_type = 'assessment'");
        $assessment->execute(['offering' => $offeringId]);
        $problem = $this->db->prepare("UPDATE subject_grade_items item INNER JOIN coding_problems problem ON problem.id = item.coding_problem_id
            SET item.title = CONCAT(problem.code, ' - ', problem.title), item.updated_at = NOW()
            WHERE item.faculty_subject_id = :offering AND item.source_type = 'problem'");
        $problem->execute(['offering' => $offeringId]);
        $problemItems = $this->db->prepare("SELECT id, coding_problem_id FROM subject_grade_items WHERE faculty_subject_id = :offering AND source_type = 'problem'");
        $problemItems->execute(['offering' => $offeringId]);
        $updateMaximum = $this->db->prepare('UPDATE subject_grade_items SET max_points = :maximum, updated_at = NOW() WHERE id = :id AND faculty_subject_id = :offering');
        foreach ($problemItems->fetchAll() as $item) {
            $maximum = $this->problemRubricMaximumPoints((int) $item['coding_problem_id']);
            if ($maximum !== null) $updateMaximum->execute(['maximum' => $maximum, 'id' => $item['id'], 'offering' => $offeringId]);
        }
        $this->db->prepare("DELETE item FROM subject_grade_items item WHERE item.faculty_subject_id = :offering AND item.source_type = 'assessment' AND NOT EXISTS (SELECT 1 FROM assessment_bank_subjects link WHERE link.faculty_subject_id = item.faculty_subject_id AND link.assessment_bank_id = item.assessment_bank_id)")->execute(['offering' => $offeringId]);
        $this->db->prepare("DELETE item FROM subject_grade_items item WHERE item.faculty_subject_id = :offering AND item.source_type = 'problem' AND NOT EXISTS (SELECT 1 FROM coding_problem_subjects link WHERE link.faculty_subject_id = item.faculty_subject_id AND link.problem_id = item.coding_problem_id)")->execute(['offering' => $offeringId]);
    }

    private function linkedActivity(int $offeringId, string $sourceType, int $sourceId): array
    {
        if ($sourceType === 'assessment') {
            $statement = $this->db->prepare("SELECT bank.id, CONCAT(bank.code, ' - ', bank.title) AS title,
                GREATEST(1, COALESCE((SELECT SUM(question.points) FROM assessment_bank_questions question WHERE question.assessment_bank_id = bank.id), 0)) AS max_points
                FROM assessment_bank_subjects link INNER JOIN assessment_banks bank ON bank.id = link.assessment_bank_id
                WHERE link.faculty_subject_id = :offering AND bank.id = :source LIMIT 1");
        } else {
            $statement = $this->db->prepare("SELECT problem.id, CONCAT(problem.code, ' - ', problem.title) AS title,
                100.00 AS max_points
                FROM coding_problem_subjects link INNER JOIN coding_problems problem ON problem.id = link.problem_id
                WHERE link.faculty_subject_id = :offering AND problem.id = :source LIMIT 1");
        }
        $statement->execute(['offering' => $offeringId, 'source' => $sourceId]); $activity = $statement->fetch();
        if (!$activity) throw new HttpException(422, 'The selected activity does not belong to this subject.');
        $maximum = $sourceType === 'problem' ? $this->problemRubricMaximumPoints((int) $activity['id']) : null;
        return ['id' => (int) $activity['id'], 'title' => $activity['title'], 'max_points' => $maximum ?? (float) $activity['max_points']];
    }

    private function availableActivities(int $offeringId): array
    {
        $statement = $this->db->prepare("SELECT 'assessment' AS source_type, bank.id AS source_id, bank.bank_type AS activity_type,
                bank.code, bank.title, bank.is_active,
                GREATEST(1, COALESCE((SELECT SUM(question.points) FROM assessment_bank_questions question WHERE question.assessment_bank_id = bank.id), 0)) AS max_points,
                'automatic' AS results_mode,
                (SELECT COUNT(*) FROM student_assessment_attempts attempt WHERE attempt.assessment_bank_id = bank.id AND attempt.faculty_subject_id = link.faculty_subject_id) AS results_count
            FROM assessment_bank_subjects link INNER JOIN assessment_banks bank ON bank.id = link.assessment_bank_id
            WHERE link.faculty_subject_id = :assessment_offering
                AND NOT EXISTS (SELECT 1 FROM subject_grade_items item WHERE item.faculty_subject_id = link.faculty_subject_id AND item.assessment_bank_id = bank.id)
            UNION ALL
            SELECT 'problem' AS source_type, problem.id AS source_id, 'coding' AS activity_type,
                problem.code, problem.title, problem.is_active,
                100.00 AS max_points,
                'manual' AS results_mode,
                (SELECT COUNT(*) FROM coding_problem_evaluations evaluation INNER JOIN coding_problem_work work ON work.student_id = evaluation.student_id AND work.problem_id = evaluation.problem_id AND work.status = 'submitted' WHERE evaluation.problem_id = problem.id AND evaluation.final_score IS NOT NULL) AS results_count
            FROM coding_problem_subjects link INNER JOIN coding_problems problem ON problem.id = link.problem_id
            WHERE link.faculty_subject_id = :problem_offering
                AND NOT EXISTS (SELECT 1 FROM subject_grade_items item WHERE item.faculty_subject_id = link.faculty_subject_id AND item.coding_problem_id = problem.id)
            ORDER BY activity_type, code, title");
        $statement->execute(['assessment_offering' => $offeringId, 'problem_offering' => $offeringId]);
        return array_map(function (array $row): array {
            $rubricMaximum = $row['source_type'] === 'problem' ? $this->problemRubricMaximumPoints((int) $row['source_id']) : null;
            return [
                'source_type' => $row['source_type'], 'source_id' => (int) $row['source_id'], 'activity_type' => $row['activity_type'],
                'code' => $row['code'], 'title' => $row['title'], 'is_active' => (bool) $row['is_active'], 'max_points' => $rubricMaximum ?? (float) $row['max_points'],
                'results_mode' => $rubricMaximum !== null ? 'rubric' : $row['results_mode'], 'results_count' => (int) $row['results_count'],
            ];
        }, $statement->fetchAll());
    }

    private function offering(int $facultyId, int $offeringId): array
    {
        $statement = $this->db->prepare("SELECT fs.id, fs.section, fs.academic_year, fs.academic_term, fs.is_active,
            subject.code AS subject_code, subject.name AS subject_name, program.code AS program_code, program.name AS program_name
            FROM faculty_subjects fs INNER JOIN subjects subject ON subject.id = fs.subject_id INNER JOIN programs program ON program.id = subject.program_id
            WHERE fs.id = :offering AND fs.faculty_id = :faculty LIMIT 1");
        $statement->execute(['offering' => $offeringId, 'faculty' => $facultyId]); $row = $statement->fetch();
        if (!$row) throw new HttpException(404, 'Faculty subject not found.');
        return ['id' => (int) $row['id'], 'section' => $row['section'], 'academic_year' => $row['academic_year'], 'academic_term' => $row['academic_term'], 'is_active' => (bool) $row['is_active'], 'subject_code' => $row['subject_code'], 'subject_name' => $row['subject_name'], 'program_code' => $row['program_code'], 'program_name' => $row['program_name']];
    }

    private function settings(int $offeringId): array
    { $statement = $this->db->prepare('SELECT * FROM subject_grading_settings WHERE faculty_subject_id = :offering LIMIT 1'); $statement->execute(['offering' => $offeringId]); return $statement->fetch() ?: []; }

    private function categories(int $offeringId): array
    { $statement = $this->db->prepare("SELECT * FROM subject_grade_categories WHERE faculty_subject_id = :offering ORDER BY FIELD(grading_period, 'midterm', 'final'), position, id"); $statement->execute(['offering' => $offeringId]); return $statement->fetchAll(); }

    private function category(int $offeringId, int $categoryId): array
    { $statement = $this->db->prepare('SELECT * FROM subject_grade_categories WHERE id = :id AND faculty_subject_id = :offering LIMIT 1'); $statement->execute(['id' => $categoryId, 'offering' => $offeringId]); $row = $statement->fetch(); if (!$row) throw new HttpException(422, 'The selected grading category is invalid.'); return $row; }

    private function items(int $offeringId): array
    {
        $statement = $this->db->prepare("SELECT item.*, category.grading_period, category.category_key, category.name AS category_name, category.weight AS category_weight,
            bank.bank_type, bank.code AS assessment_code, problem.code AS problem_code, problem_rubric.problem_id AS problem_rubric_id
            FROM subject_grade_items item INNER JOIN subject_grade_categories category ON category.id = item.category_id
            LEFT JOIN assessment_banks bank ON bank.id = item.assessment_bank_id LEFT JOIN coding_problems problem ON problem.id = item.coding_problem_id
            LEFT JOIN coding_problem_rubrics problem_rubric ON problem_rubric.problem_id = item.coding_problem_id
            WHERE item.faculty_subject_id = :offering ORDER BY FIELD(category.grading_period, 'midterm', 'final'), category.position, item.position, item.title, item.id");
        $statement->execute(['offering' => $offeringId]); return $statement->fetchAll();
    }

    private function item(int $offeringId, int $itemId): array
    { foreach ($this->items($offeringId) as $item) if ((int) $item['id'] === $itemId) return $item; throw new HttpException(404, 'Grade item not found.'); }

    private function students(int $offeringId): array
    {
        $statement = $this->db->prepare("SELECT student.id, student.name, student.username, profile.student_number
            FROM faculty_subject_students enrollment INNER JOIN users student ON student.id = enrollment.student_id AND student.role = 'student'
            LEFT JOIN student_profiles profile ON profile.user_id = student.id WHERE enrollment.faculty_subject_id = :offering
            ORDER BY student.last_name, student.first_name, student.name");
        $statement->execute(['offering' => $offeringId]); return $statement->fetchAll();
    }

    private function assertUnlocked(int $offeringId, string $period): void
    { $settings = $this->settings($offeringId); if (($settings[$period . '_status'] ?? 'draft') === 'locked') throw new HttpException(422, ucfirst($period) . ' grading is locked. Unlock it before making changes.'); }

    private function nextItemPosition(int $offeringId, int $categoryId): int
    { $statement = $this->db->prepare('SELECT COALESCE(MAX(position), 0) + 1 FROM subject_grade_items WHERE faculty_subject_id = :offering AND category_id = :category'); $statement->execute(['offering' => $offeringId, 'category' => $categoryId]); return min(65535, max(1, (int) $statement->fetchColumn())); }

    private function categoryPayload(array $category, int $itemsCount): array
    { return ['id' => (int) $category['id'], 'grading_period' => $category['grading_period'], 'category_key' => $category['category_key'], 'name' => $category['name'], 'weight' => (float) $category['weight'], 'position' => (int) $category['position'], 'items_count' => $itemsCount]; }

    private function itemPayload(array $item): array
    { return ['id' => (int) $item['id'], 'category_id' => (int) $item['category_id'], 'grading_period' => $item['grading_period'], 'category_key' => $item['category_key'], 'category_name' => $item['category_name'], 'source_type' => $item['source_type'], 'source_id' => $item['assessment_bank_id'] !== null ? (int) $item['assessment_bank_id'] : ($item['coding_problem_id'] !== null ? (int) $item['coding_problem_id'] : null), 'bank_type' => $item['bank_type'], 'rubric_enabled' => $item['problem_rubric_id'] !== null, 'title' => $item['title'], 'max_points' => (float) $item['max_points'], 'counts_toward_grade' => (bool) $item['counts_toward_grade'], 'due_at' => $item['due_at'], 'position' => (int) $item['position']]; }

    private function problemRubricMaximumPoints(int $problemId): ?float
    {
        $statement = $this->db->prepare('SELECT criteria_json FROM coding_problem_rubrics WHERE problem_id = ? LIMIT 1');
        $statement->execute([$problemId]); $json = $statement->fetchColumn();
        if (!is_string($json) || $json === '') return null;
        $criteria = json_decode($json, true);
        if (!is_array($criteria)) return null;
        $total = 0.0;
        foreach ($criteria as $criterion) {
            if (!is_array($criterion)) continue;
            $points = $criterion['max_points'] ?? null;
            if (is_numeric($points) && is_finite((float) $points) && (float) $points > 0) $total += (float) $points;
        }
        return $total > 0 ? round($total, 2) : null;
    }

    private function legend(float $score): array
    {
        $rounded = (int) round($score, 0, PHP_ROUND_HALF_UP);
        if ($rounded >= 99) return ['rounded_score' => $rounded, 'equivalent' => '1.00', 'result' => 'Passed'];
        if ($rounded >= 96) return ['rounded_score' => $rounded, 'equivalent' => '1.25', 'result' => 'Passed'];
        if ($rounded >= 93) return ['rounded_score' => $rounded, 'equivalent' => '1.50', 'result' => 'Passed'];
        if ($rounded >= 90) return ['rounded_score' => $rounded, 'equivalent' => '1.75', 'result' => 'Passed'];
        if ($rounded >= 87) return ['rounded_score' => $rounded, 'equivalent' => '2.00', 'result' => 'Passed'];
        if ($rounded >= 84) return ['rounded_score' => $rounded, 'equivalent' => '2.25', 'result' => 'Passed'];
        if ($rounded >= 81) return ['rounded_score' => $rounded, 'equivalent' => '2.50', 'result' => 'Passed'];
        if ($rounded >= 78) return ['rounded_score' => $rounded, 'equivalent' => '2.75', 'result' => 'Passed'];
        if ($rounded >= 75) return ['rounded_score' => $rounded, 'equivalent' => '3.00', 'result' => 'Passed'];
        if ($rounded >= 73) return ['rounded_score' => $rounded, 'equivalent' => '4.00', 'result' => 'Conditional'];
        return ['rounded_score' => $rounded, 'equivalent' => '5.00', 'result' => 'Failed'];
    }

    private function legendRows(): array
    { return [['range' => '99–100', 'equivalent' => '1.00', 'result' => 'Passed'], ['range' => '96–98', 'equivalent' => '1.25', 'result' => 'Passed'], ['range' => '93–95', 'equivalent' => '1.50', 'result' => 'Passed'], ['range' => '90–92', 'equivalent' => '1.75', 'result' => 'Passed'], ['range' => '87–89', 'equivalent' => '2.00', 'result' => 'Passed'], ['range' => '84–86', 'equivalent' => '2.25', 'result' => 'Passed'], ['range' => '81–83', 'equivalent' => '2.50', 'result' => 'Passed'], ['range' => '78–80', 'equivalent' => '2.75', 'result' => 'Passed'], ['range' => '75–77', 'equivalent' => '3.00', 'result' => 'Passed'], ['range' => '73–74', 'equivalent' => '4.00', 'result' => 'Conditional'], ['range' => '72 and below', 'equivalent' => '5.00', 'result' => 'Failed']]; }

    private function average(array $values): float
    { return $values === [] ? 0.0 : round(array_sum($values) / count($values), 2); }

    private function recentAudit(int $offeringId): array
    {
        $statement = $this->db->prepare("SELECT audit.id, audit.action, audit.created_at, faculty.name AS faculty_name, student.name AS student_name, item.title AS item_title
            FROM subject_grade_audit_log audit LEFT JOIN users faculty ON faculty.id = audit.faculty_id LEFT JOIN users student ON student.id = audit.student_id
            LEFT JOIN subject_grade_items item ON item.id = audit.grade_item_id WHERE audit.faculty_subject_id = :offering ORDER BY audit.id DESC LIMIT 30");
        $statement->execute(['offering' => $offeringId]); return array_map(static function (array $row): array { return ['id' => (int) $row['id'], 'action' => $row['action'], 'faculty_name' => $row['faculty_name'], 'student_name' => $row['student_name'], 'item_title' => $row['item_title'], 'created_at' => $row['created_at']]; }, $statement->fetchAll());
    }

    private function audit(int $offeringId, ?int $itemId, ?int $studentId, int $facultyId, string $action, $previous, $current): void
    {
        $statement = $this->db->prepare('INSERT INTO subject_grade_audit_log (faculty_subject_id, grade_item_id, student_id, faculty_id, action, previous_json, current_json, created_at) VALUES (:offering, :item, :student, :faculty, :action, :previous, :current, NOW())');
        $statement->execute(['offering' => $offeringId, 'item' => $itemId, 'student' => $studentId, 'faculty' => $facultyId, 'action' => $action, 'previous' => $previous === null ? null : json_encode($previous, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'current' => $current === null ? null : json_encode($current, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);
    }

    private function transaction(callable $callback)
    {
        $owns = !$this->db->inTransaction(); if ($owns) $this->db->beginTransaction();
        try { $result = $callback(); if ($owns) $this->db->commit(); return $result; }
        catch (Throwable $exception) { if ($owns && $this->db->inTransaction()) $this->db->rollBack(); throw $exception; }
    }
}
