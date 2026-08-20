<?php
declare(strict_types=1);

namespace Codify\Controllers;

use Codify\Core\HttpException;
use Codify\Core\Request;
use Codify\Core\Response;
use Codify\Repositories\SubjectGradebookRepository;
use Codify\Services\AuthGuard;
use Codify\Support\Validator;

final class SubjectGradebookController
{
    private $gradebooks;
    private $guard;

    public function __construct(SubjectGradebookRepository $gradebooks, AuthGuard $guard)
    { $this->gradebooks = $gradebooks; $this->guard = $guard; }

    public function show(Request $request): void
    { $faculty = $this->faculty($request); Response::success($this->gradebooks->gradebook((int) $faculty['id'], $this->offeringId($request))); }

    public function updateSettings(Request $request): void
    {
        $faculty = $this->faculty($request); $input = $request->json(); $validator = new Validator($input);
        $midterm = $validator->oneOf('midterm_status', ['draft', 'published', 'locked'], 'draft');
        $final = $validator->oneOf('final_status', ['draft', 'published', 'locked'], 'draft');
        $rawCategories = $input['categories'] ?? null; $weights = [];
        if (!is_array($rawCategories) || $rawCategories === []) $validator->add('categories', 'Supply the Midterm and Final category weights.');
        else foreach ($rawCategories as $category) {
            if (!is_array($category)) { $validator->add('categories', 'One or more grading categories are invalid.'); continue; }
            $id = filter_var($category['id'] ?? null, FILTER_VALIDATE_INT); $weight = $this->decimal($category['weight'] ?? null);
            if ($id === false || $id < 1 || $weight === null || $weight < 0 || $weight > 100) { $validator->add('categories', 'Category weights must be numbers from 0 to 100.'); continue; }
            $weights[(int) $id] = $weight;
        }
        $validator->throwIfFailed();
        Response::success($this->gradebooks->updateSettings((int) $faculty['id'], $this->offeringId($request), $weights, $midterm, $final), 'Grading setup saved.');
    }

    public function storeItem(Request $request): void
    {
        $faculty = $this->faculty($request); $data = $this->createItemData($request->json());
        $gradebook = $data['source_type'] === 'manual'
            ? $this->gradebooks->createManualItem((int) $faculty['id'], $this->offeringId($request), $data)
            : $this->gradebooks->createLinkedItem((int) $faculty['id'], $this->offeringId($request), $data);
        Response::success($gradebook, 'Activity added to the gradebook.', 201);
    }

    public function updateItem(Request $request): void
    {
        $faculty = $this->faculty($request); $data = $this->updateItemData($request->json());
        Response::success($this->gradebooks->updateItem((int) $faculty['id'], $this->offeringId($request), $this->itemId($request), $data), 'Grade item updated.');
    }

    public function destroyItem(Request $request): void
    {
        $faculty = $this->faculty($request);
        Response::success($this->gradebooks->deleteItem((int) $faculty['id'], $this->offeringId($request), $this->itemId($request)), 'Activity removed from the gradebook.');
    }

    public function saveScores(Request $request): void
    {
        $faculty = $this->faculty($request); $input = $request->json(); $rawEntries = $input['entries'] ?? null;
        if (!is_array($rawEntries) || $rawEntries === []) throw new HttpException(422, 'No changed student grades were supplied.');
        if (count($rawEntries) > 2000) throw new HttpException(422, 'No more than 2,000 grade entries can be saved at once.');
        $entries = [];
        foreach ($rawEntries as $raw) {
            if (!is_array($raw)) throw new HttpException(422, 'One or more grade entries are invalid.');
            $itemId = filter_var($raw['item_id'] ?? null, FILTER_VALIDATE_INT); $studentId = filter_var($raw['student_id'] ?? null, FILTER_VALIDATE_INT);
            $status = (string) ($raw['status'] ?? 'ungraded');
            if ($itemId === false || $itemId < 1 || $studentId === false || $studentId < 1 || !in_array($status, ['ungraded', 'graded', 'missing', 'excused', 'pending'], true)) throw new HttpException(422, 'One or more grade entries are invalid.');
            $score = $raw['score'] === null || $raw['score'] === '' ? null : $this->decimal($raw['score']);
            if ($raw['score'] !== null && $raw['score'] !== '' && $score === null) throw new HttpException(422, 'Scores must be valid numbers.');
            $remarks = trim((string) ($raw['remarks'] ?? '')); if ($this->length($remarks) > 1000) throw new HttpException(422, 'Grade remarks may not exceed 1,000 characters.');
            $key = (int) $itemId . ':' . (int) $studentId;
            $entries[$key] = ['item_id' => (int) $itemId, 'student_id' => (int) $studentId, 'status' => $status, 'score' => $score, 'remarks' => $remarks === '' ? null : $remarks];
        }
        Response::success($this->gradebooks->saveScores((int) $faculty['id'], $this->offeringId($request), array_values($entries)), 'Changed student grades saved.');
    }

    private function createItemData(array $input): array
    {
        $validator = new Validator($input); $sourceType = $validator->oneOf('source_type', ['assessment', 'problem', 'manual'], 'manual');
        $categoryId = $validator->integer('category_id', 1, PHP_INT_MAX, 0); $included = $validator->boolean('counts_toward_grade', true);
        $dueAt = $this->date($input['due_at'] ?? null, $validator);
        $sourceId = null; $title = ''; $maxPoints = 0.0;
        if ($sourceType === 'manual') {
            $title = $validator->requiredString('title', 200); $maxPoints = $this->decimal($input['max_points'] ?? null);
            if ($maxPoints === null || $maxPoints <= 0 || $maxPoints > 1000000) $validator->add('max_points', 'Maximum points must be greater than zero and no more than 1,000,000.');
        } else {
            $sourceId = $validator->integer('source_id', 1, PHP_INT_MAX, 0);
        }
        $validator->throwIfFailed();
        return ['source_type' => $sourceType, 'source_id' => $sourceId, 'title' => $title, 'category_id' => $categoryId, 'max_points' => $maxPoints, 'counts_toward_grade' => $included, 'due_at' => $dueAt];
    }

    private function updateItemData(array $input): array
    {
        $validator = new Validator($input); $title = $validator->requiredString('title', 200);
        $categoryId = $validator->integer('category_id', 1, PHP_INT_MAX, 0); $included = $validator->boolean('counts_toward_grade', true);
        $maxPoints = $this->decimal($input['max_points'] ?? null);
        if ($maxPoints === null || $maxPoints <= 0 || $maxPoints > 1000000) $validator->add('max_points', 'Maximum points must be greater than zero and no more than 1,000,000.');
        $dueAt = $this->date($input['due_at'] ?? null, $validator); $validator->throwIfFailed();
        return ['title' => $title, 'category_id' => $categoryId, 'max_points' => $maxPoints, 'counts_toward_grade' => $included, 'due_at' => $dueAt];
    }

    private function date($value, Validator $validator): ?string
    {
        $date = trim((string) ($value ?? '')); if ($date === '') return null;
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$parsed || $parsed->format('Y-m-d') !== $date) { $validator->add('due_at', 'The due date must be a valid calendar date.'); return null; }
        return $parsed->format('Y-m-d 23:59:59');
    }

    private function decimal($value): ?float
    { if (is_int($value) || is_float($value) || (is_string($value) && preg_match('/^-?\d+(?:\.\d+)?$/', trim($value)) === 1)) return round((float) $value, 2); return null; }

    private function faculty(Request $request): array { return $this->guard->authenticate($request, true, 'faculty'); }
    private function offeringId(Request $request): int { $id = filter_var($request->route('offering'), FILTER_VALIDATE_INT); if ($id === false || $id < 1) throw new HttpException(404, 'Faculty subject not found.'); return (int) $id; }
    private function itemId(Request $request): int { $id = filter_var($request->route('item'), FILTER_VALIDATE_INT); if ($id === false || $id < 1) throw new HttpException(404, 'Grade item not found.'); return (int) $id; }
    private function length(string $value): int { return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value); }
}
