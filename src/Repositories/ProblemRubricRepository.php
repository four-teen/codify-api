<?php
declare(strict_types=1);
namespace Codify\Repositories;

use Codify\Core\HttpException;
use Codify\Services\PythonRubricScorer;
use PDO;

final class ProblemRubricRepository
{
    private $db;
    public const FEATURES = ['input', 'print', 'int', 'float', 'range', 'len', 'assignment', 'addition', 'multiplication', 'for_loop', 'while_loop', 'conditional', 'function', 'return', 'list', 'dictionary', 'exception_handling', 'list_comprehension'];
    public function __construct(PDO $db) { $this->db = $db; }

    public static function validate($value): ?array
    {
        if ($value === null) return null;
        if (!is_array($value)) throw new HttpException(422, 'Supply the rubric as an object.');
        $name = trim((string) ($value['name'] ?? ''));
        $description = trim((string) ($value['description'] ?? ''));
        $criteria = $value['criteria'] ?? [];
        if ($name === '' || strlen($name) > 150 || strlen($description) > 5000) throw new HttpException(422, 'Enter a rubric name of up to 150 characters and description of up to 5,000 characters.');
        if (!is_array($criteria) || count($criteria) < 1 || count($criteria) > 20) throw new HttpException(422, 'Add between 1 and 20 rubric criteria.');
        $rows = []; $seen = []; $total = 0;
        foreach (array_values($criteria) as $index => $criterion) {
            if (!is_array($criterion)) throw new HttpException(422, 'A rubric criterion is invalid.');
            $id = (string) ($criterion['id'] ?? 'criterion_' . $index);
            $title = trim((string) ($criterion['title'] ?? ''));
            $text = trim((string) ($criterion['description'] ?? ''));
            $points = $criterion['max_points'] ?? null;
            $check = (string) ($criterion['check'] ?? 'manual');
            if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id) || isset($seen[$id])) throw new HttpException(422, 'Criterion IDs must be unique.');
            if ($title === '' || strlen($title) > 150 || strlen($text) > 3000) throw new HttpException(422, 'Each criterion needs a title of up to 150 characters and a description of up to 3,000 characters.');
            if (!is_numeric($points) || !is_finite((float) $points) || (float) $points < 0.01 || (float) $points > 1000) throw new HttpException(422, 'Criterion points must be between 0.01 and 1,000.');
            if (!in_array($check, ['manual', 'syntax', 'features', 'formatting'], true)) throw new HttpException(422, 'Choose a valid criterion check.');
            $features = $criterion['required_features'] ?? [];
            if (!is_array($features) || array_diff($features, self::FEATURES)) throw new HttpException(422, 'A required Python construct is invalid.');
            $features = $check === 'features' ? array_values(array_unique($features)) : [];
            if ($check === 'features' && !$features) throw new HttpException(422, 'Select at least one required construct for a construct check.');
            $points = round((float) $points, 2); $total += $points; $seen[$id] = true;
            $rows[] = ['id' => $id, 'title' => $title, 'description' => $text, 'max_points' => $points, 'check' => $check, 'required_features' => $features];
        }
        return ['name' => $name, 'description' => $description, 'criteria' => $rows, 'max_points' => round($total, 2)];
    }

    public function templates(int $faculty): array
    {
        $query = $this->db->prepare('SELECT * FROM coding_rubric_templates WHERE faculty_id = ? ORDER BY name, id');
        $query->execute([$faculty]);
        return array_map([$this, 'payload'], $query->fetchAll());
    }

    public function saveTemplate(int $faculty, ?int $id, array $input): array
    {
        $rubric = self::validate($input);
        if ($id) {
            $this->template($faculty, $id);
            $this->db->prepare('UPDATE coding_rubric_templates SET name = ?, description = ?, criteria_json = ?, updated_at = NOW() WHERE id = ? AND faculty_id = ?')->execute([$rubric['name'], $rubric['description'], json_encode($rubric['criteria']), $id, $faculty]);
        } else {
            $this->db->prepare('INSERT INTO coding_rubric_templates (faculty_id, name, description, criteria_json, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())')->execute([$faculty, $rubric['name'], $rubric['description'], json_encode($rubric['criteria'])]);
            $id = (int) $this->db->lastInsertId();
        }
        return $this->template($faculty, $id);
    }

    public function deleteTemplate(int $faculty, int $id): void
    {
        $this->template($faculty, $id);
        $this->db->prepare('DELETE FROM coding_rubric_templates WHERE id = ? AND faculty_id = ?')->execute([$id, $faculty]);
    }

    private function template(int $faculty, int $id): array
    {
        $query = $this->db->prepare('SELECT * FROM coding_rubric_templates WHERE faculty_id = ? AND id = ?');
        $query->execute([$faculty, $id]); $row = $query->fetch();
        if (!$row) throw new HttpException(404, 'Rubric template not found.');
        return $this->payload($row);
    }

    public function problem(int $problem): ?array
    {
        $query = $this->db->prepare('SELECT * FROM coding_problem_rubrics WHERE problem_id = ?');
        $query->execute([$problem]); $row = $query->fetch();
        return $row ? $this->payload($row) : null;
    }

    private function payload(array $row): array
    {
        $criteria = json_decode($row['criteria_json'], true);
        return ['id' => isset($row['id']) ? (int) $row['id'] : null, 'template_id' => isset($row['template_id']) ? (int) $row['template_id'] : null, 'name' => $row['name'], 'description' => $row['description'] ?? '', 'criteria' => $criteria, 'max_points' => round(array_sum(array_column($criteria, 'max_points')), 2)];
    }

    public function attach(int $faculty, int $problem, $input): void
    {
        $this->assertOwner($faculty, $problem);
        $rubric = self::validate($input); $existing = $this->problem($problem);
        if ($existing && ($rubric === null || $existing['name'] !== $rubric['name'] || $existing['description'] !== $rubric['description'] || $existing['criteria'] != $rubric['criteria'])) {
            $count = $this->db->prepare('SELECT COUNT(*) FROM coding_problem_work WHERE problem_id = ?'); $count->execute([$problem]);
            if ((int) $count->fetchColumn() > 0) throw new HttpException(409, 'Students have started this problem. Keep its published rubric unchanged, or create a new problem with the revised rubric.');
        }
        if (!$rubric) { $this->db->prepare('DELETE FROM coding_problem_rubrics WHERE problem_id = ?')->execute([$problem]); return; }
        $templateId = !empty($input['template_id']) ? (int) $input['template_id'] : null;
        if ($templateId) $this->template($faculty, $templateId);
        $this->db->prepare('INSERT INTO coding_problem_rubrics (problem_id, template_id, name, description, criteria_json, updated_at) VALUES (?, ?, ?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE template_id = VALUES(template_id), name = VALUES(name), description = VALUES(description), criteria_json = VALUES(criteria_json), updated_at = NOW()')->execute([$problem, $templateId, $rubric['name'], $rubric['description'], json_encode($rubric['criteria'])]);
    }

    public function snapshot(int $student, int $problem): void
    {
        $rubric = $this->problem($problem);
        $query = $this->db->prepare('SELECT problem_statement FROM coding_problems WHERE id = ?'); $query->execute([$problem]);
        $this->db->prepare('INSERT IGNORE INTO coding_problem_evaluations (student_id, problem_id, rubric_json, instructions_snapshot) VALUES (?, ?, ?, ?)')->execute([$student, $problem, $rubric ? json_encode($rubric) : null, $query->fetchColumn()]);
    }

    public function evaluation(int $student, int $problem): ?array
    {
        $query = $this->db->prepare('SELECT * FROM coding_problem_evaluations WHERE student_id = ? AND problem_id = ?');
        $query->execute([$student, $problem]); $row = $query->fetch();
        if (!$row) return null;
        return ['rubric' => json_decode($row['rubric_json'] ?? 'null', true), 'initial' => json_decode($row['initial_json'] ?? 'null', true), 'final' => json_decode($row['final_json'] ?? 'null', true), 'final_score' => $row['final_score'] === null ? null : (float) $row['final_score'], 'feedback' => $row['feedback'], 'graded_at' => $row['graded_at']];
    }

    public function generate(int $faculty, int $problem, int $student): array
    {
        $this->submitted($faculty, $problem, $student);
        $evaluation = $this->gradingRubric($student, $problem);
        if ($evaluation['initial']) return $evaluation;
        $query = $this->db->prepare('SELECT submitted_code FROM coding_problem_work WHERE student_id = ? AND problem_id = ?'); $query->execute([$student, $problem]);
        $initial = (new PythonRubricScorer())->score((string) $query->fetchColumn(), $evaluation['rubric']);
        $this->db->prepare('UPDATE coding_problem_evaluations SET initial_json = ? WHERE student_id = ? AND problem_id = ? AND initial_json IS NULL')->execute([json_encode($initial), $student, $problem]);
        return $this->evaluation($student, $problem);
    }

    public function finalize(int $faculty, int $problem, int $student, array $input): array
    {
        $this->submitted($faculty, $problem, $student);
        $this->assertGradebookUnlocked($problem);
        $evaluation = $this->gradingRubric($student, $problem);
        $scores = $input['scores'] ?? []; $rows = []; $total = 0;
        if (!is_array($scores) || count($scores) !== count($evaluation['rubric']['criteria'])) throw new HttpException(422, 'Give a final score for every rubric criterion.');
        foreach ($evaluation['rubric']['criteria'] as $criterion) {
            $value = $scores[$criterion['id']] ?? null;
            if (!is_numeric($value) || !is_finite((float) $value) || (float) $value < 0 || (float) $value > $criterion['max_points']) throw new HttpException(422, 'Final scores must be between zero and the criterion maximum.');
            $value = round((float) $value, 2); $total += $value;
            $rows[] = ['id' => $criterion['id'], 'title' => $criterion['title'], 'score' => $value, 'max_points' => $criterion['max_points']];
        }
        $feedback = trim((string) ($input['feedback'] ?? ''));
        if (strlen($feedback) > 10000) throw new HttpException(422, 'Feedback is limited to 10,000 characters.');
        $this->db->prepare('UPDATE coding_problem_evaluations SET final_json = ?, final_score = ?, feedback = ?, graded_by = ?, graded_at = NOW() WHERE student_id = ? AND problem_id = ?')->execute([json_encode($rows), round($total, 2), $feedback, $faculty, $student, $problem]);
        return $this->evaluation($student, $problem);
    }

    private function assertGradebookUnlocked(int $problem): void
    {
        $statement = $this->db->prepare("SELECT category.grading_period, settings.midterm_status, settings.final_status
            FROM subject_grade_items item
            INNER JOIN subject_grade_categories category ON category.id = item.category_id
            INNER JOIN subject_grading_settings settings ON settings.faculty_subject_id = item.faculty_subject_id
            WHERE item.source_type = 'problem' AND item.coding_problem_id = ?");
        $statement->execute([$problem]);
        foreach ($statement->fetchAll() as $row) {
            $period = $row['grading_period'];
            if (($row[$period . '_status'] ?? 'draft') === 'locked') {
                throw new HttpException(422, ucfirst($period) . ' grading is locked. Unlock it before changing a Python rubric score.');
            }
        }
    }

    private function gradingRubric(int $student, int $problem): array
    {
        $this->snapshot($student, $problem);
        $evaluation = $this->evaluation($student, $problem);
        // Older answers may predate rubrics. Snapshot the rubric at first grading.
        if (!$evaluation['rubric']) {
            $rubric = $this->problem($problem);
            if (!$rubric) throw new HttpException(422, 'Attach a rubric to this problem before scoring its answers.');
            $rubric['added_after_attempt'] = true;
            $this->db->prepare('UPDATE coding_problem_evaluations SET rubric_json = ? WHERE student_id = ? AND problem_id = ? AND rubric_json IS NULL')->execute([json_encode($rubric), $student, $problem]);
            $evaluation = $this->evaluation($student, $problem);
        }
        return $evaluation;
    }

    private function submitted(int $faculty, int $problem, int $student): void
    {
        $this->assertOwner($faculty, $problem);
        $query = $this->db->prepare("SELECT student_id FROM coding_problem_work WHERE student_id = ? AND problem_id = ? AND status = 'submitted'"); $query->execute([$student, $problem]);
        if (!$query->fetch()) throw new HttpException(404, 'Submitted answer not found.');
    }

    private function assertOwner(int $faculty, int $problem): void
    {
        $query = $this->db->prepare('SELECT id FROM coding_problems WHERE id = ? AND faculty_id = ?'); $query->execute([$problem, $faculty]);
        if (!$query->fetch()) throw new HttpException(404, 'Python problem not found.');
    }
}
