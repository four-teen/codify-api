<?php
declare(strict_types=1);

namespace Codify\Controllers;

use Codify\Core\HttpException;
use Codify\Core\Request;
use Codify\Core\Response;
use Codify\Repositories\AssessmentBankRepository;
use Codify\Repositories\SystemSettingRepository;
use Codify\Services\AuthGuard;
use Codify\Support\Validator;

final class AssessmentBankController
{
    private $banks;
    private $settings;
    private $guard;

    public function __construct(AssessmentBankRepository $banks, SystemSettingRepository $settings, AuthGuard $guard)
    { $this->banks = $banks; $this->settings = $settings; $this->guard = $guard; }

    public function index(Request $request): void
    {
        $faculty = $this->faculty($request); $settings = $this->settings->current(); $filters = $this->filters($request);
        Response::success([
            'banks' => $this->banks->banks((int) $faculty['id'], $filters),
            'offerings' => $this->banks->offerings((int) $faculty['id'], $settings['academic_year'], $settings['academic_term']),
            'metrics' => $this->banks->metrics((int) $faculty['id']),
            'filters' => $filters,
        ]);
    }

    public function show(Request $request): void
    { $faculty = $this->faculty($request); Response::success($this->banks->bank((int) $faculty['id'], $this->bankId($request))); }

    public function store(Request $request): void
    {
        $faculty = $this->faculty($request); $payload = $this->payload($request->json()); $settings = $this->settings->current();
        $bank = $this->banks->create((int) $faculty['id'], $payload['bank'], $payload['subject_ids'], $payload['questions'], $settings['academic_year'], $settings['academic_term']);
        Response::success($bank, ucfirst($payload['bank']['bank_type']) . ' bank created.', 201);
    }

    public function update(Request $request): void
    {
        $faculty = $this->faculty($request); $payload = $this->payload($request->json()); $settings = $this->settings->current();
        $bank = $this->banks->update((int) $faculty['id'], $this->bankId($request), $payload['bank'], $payload['subject_ids'], $payload['questions'], $settings['academic_year'], $settings['academic_term']);
        Response::success($bank, ucfirst($payload['bank']['bank_type']) . ' bank updated.');
    }

    public function destroy(Request $request): void
    { $faculty = $this->faculty($request); $this->banks->delete((int) $faculty['id'], $this->bankId($request)); Response::success([], 'Quiz or exam bank deleted.'); }

    private function filters(Request $request): array
    {
        $type = strtolower(trim((string) $request->query('bank_type', '')));
        if (!in_array($type, ['', 'quiz', 'exam'], true)) $type = '';
        $status = strtolower(trim((string) $request->query('status', '')));
        if (!in_array($status, ['', 'active', 'draft'], true)) $status = '';
        $offering = filter_var($request->query('offering_id', 0), FILTER_VALIDATE_INT);
        return ['search' => trim((string) $request->query('search', '')), 'bank_type' => $type, 'status' => $status, 'offering_id' => $offering === false || $offering < 1 ? 0 : (int) $offering];
    }

    private function payload(array $input): array
    {
        $validator = new Validator($input);
        $code = strtoupper($validator->requiredString('code', 50));
        $title = $validator->requiredString('title', 200);
        $type = $validator->oneOf('bank_type', ['quiz', 'exam'], 'quiz');
        $description = $validator->optionalString('description', 5000);
        $instructions = $validator->optionalString('instructions', 30000);
        $active = $validator->boolean('is_active', false);
        if ($code !== '' && preg_match('/^[A-Z0-9._-]+$/', $code) !== 1) $validator->add('code', 'The bank code may contain only letters, numbers, dots, underscores, and hyphens.');
        $subjectIds = $this->positiveIds($input['faculty_subject_ids'] ?? null, $validator);
        $questions = $this->questions($input['questions'] ?? null, $validator, $active);
        if ($active && count($questions) < 1) $validator->add('questions', 'An active quiz or exam bank requires at least one question.');
        $validator->throwIfFailed();
        return ['bank' => ['code' => $code, 'title' => $title, 'bank_type' => $type, 'description' => $description, 'instructions' => $instructions, 'is_active' => $active], 'subject_ids' => $subjectIds, 'questions' => $questions];
    }

    private function positiveIds($value, Validator $validator): array
    {
        if (!is_array($value) || $value === []) { $validator->add('faculty_subject_ids', 'Select at least one subject from your teaching load.'); return []; }
        $ids = [];
        foreach ($value as $item) { $id = filter_var($item, FILTER_VALIDATE_INT); if ($id === false || $id < 1) { $validator->add('faculty_subject_ids', 'A selected subject is invalid.'); continue; } $ids[(int) $id] = (int) $id; }
        return array_values($ids);
    }

    private function questions($value, Validator $validator, bool $active): array
    {
        if ($value === null || $value === []) return [];
        if (!is_array($value)) { $validator->add('questions', 'Questions must be supplied as a list.'); return []; }
        if (count($value) > 100) { $validator->add('questions', 'A bank is limited to 100 questions.'); return []; }
        $allowed = ['multiple_choice', 'checkboxes', 'dropdown', 'true_false', 'short_answer', 'paragraph']; $questions = [];
        foreach ($value as $index => $question) {
            $number = $index + 1;
            if (!is_array($question)) { $validator->add('questions', "Question {$number} is invalid."); continue; }
            $type = (string) ($question['question_type'] ?? 'multiple_choice');
            if (!in_array($type, $allowed, true)) { $validator->add('questions', "Question {$number} has an invalid type."); $type = 'multiple_choice'; }
            $text = trim($this->normalizedText((string) ($question['question_text'] ?? '')));
            if ($text === '') $validator->add('questions', "Question {$number} needs question text.");
            elseif ($this->length($text) > 30000) $validator->add('questions', "Question {$number} text is too long.");
            $points = filter_var($question['points'] ?? 1, FILTER_VALIDATE_INT);
            if ($points === false || $points < 0 || $points > 1000) { $validator->add('questions', "Question {$number} points must be from 0 to 1000."); $points = 1; }
            $required = $this->boolean($question['is_required'] ?? true, true, $validator, "Question {$number} required setting is invalid.");
            $caseSensitive = $this->boolean($question['case_sensitive'] ?? false, false, $validator, "Question {$number} case-sensitivity setting is invalid.");
            $explanation = trim($this->normalizedText((string) ($question['answer_explanation'] ?? '')));
            if ($this->length($explanation) > 5000) $validator->add('questions', "Question {$number} answer explanation is too long.");
            $options = []; $correct = []; $accepted = [];
            if (in_array($type, ['multiple_choice', 'checkboxes', 'dropdown'], true)) {
                $options = $this->strings($question['options'] ?? null, 20, 500, $validator, "Question {$number} options");
                if (count($options) < 2) $validator->add('questions', "Question {$number} needs at least two answer options.");
                $correct = $this->indexes($question['correct_answers'] ?? null, count($options), $validator, $number);
                if ($active && $type === 'checkboxes' && count($correct) < 1) $validator->add('questions', "Question {$number} needs at least one correct checkbox answer.");
                if ($active && in_array($type, ['multiple_choice', 'dropdown'], true) && count($correct) !== 1) $validator->add('questions', "Question {$number} needs exactly one correct answer.");
            } elseif ($type === 'true_false') {
                $raw = is_array($question['correct_answers'] ?? null) ? array_values($question['correct_answers']) : [];
                if ($raw !== [] && in_array((string) $raw[0], ['true', 'false'], true)) $correct = [(string) $raw[0]];
                elseif ($active) $validator->add('questions', "Question {$number} needs a true or false answer key.");
            } elseif ($type === 'short_answer') {
                $accepted = $this->strings($question['accepted_answers'] ?? null, 20, 500, $validator, "Question {$number} accepted answers", true);
            }
            $questions[] = ['question_type' => $type, 'question_text' => $text, 'options' => $options, 'correct_answers' => $correct, 'accepted_answers' => $accepted, 'case_sensitive' => $type === 'short_answer' ? $caseSensitive : false, 'points' => (int) $points, 'is_required' => $required, 'answer_explanation' => $explanation === '' ? null : $explanation];
        }
        return $questions;
    }

    private function strings($value, int $maximum, int $length, Validator $validator, string $label, bool $allowEmpty = false): array
    {
        if ($value === null || $value === []) return [];
        if (!is_array($value) || count($value) > $maximum) { $validator->add('questions', "{$label} must contain no more than {$maximum} items."); return []; }
        $strings = [];
        foreach ($value as $item) {
            $string = trim((string) $item);
            if ($string === '') { if (!$allowEmpty) $validator->add('questions', "{$label} cannot contain a blank option."); continue; }
            if ($this->length($string) > $length) $validator->add('questions', "{$label} may not exceed {$length} characters each.");
            $strings[] = $string;
        }
        if (count(array_unique(array_map('strtolower', $strings))) !== count($strings)) $validator->add('questions', "{$label} must be unique.");
        return $strings;
    }

    private function indexes($value, int $optionCount, Validator $validator, int $number): array
    {
        if ($value === null || $value === []) return [];
        if (!is_array($value)) { $validator->add('questions', "Question {$number} answer key is invalid."); return []; }
        $indexes = [];
        foreach ($value as $item) {
            $index = filter_var($item, FILTER_VALIDATE_INT);
            if ($index === false || $index < 0 || $index >= $optionCount) { $validator->add('questions', "Question {$number} has an answer key outside its options."); continue; }
            $indexes[(int) $index] = (int) $index;
        }
        return array_values($indexes);
    }

    private function boolean($value, bool $default, Validator $validator, string $message): bool
    {
        $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($parsed === null) { $validator->add('questions', $message); return $default; }
        return $parsed;
    }

    private function normalizedText(string $value): string { return str_replace(["\r\n", "\r"], "\n", $value); }
    private function length(string $value): int { return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value); }
    private function faculty(Request $request): array { return $this->guard->authenticate($request, true, 'faculty'); }
    private function bankId(Request $request): int { $id = filter_var($request->route('bank'), FILTER_VALIDATE_INT); if ($id === false || $id < 1) throw new HttpException(404, 'Quiz or exam bank not found.'); return (int) $id; }
}
