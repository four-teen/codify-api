<?php
declare(strict_types=1);

namespace Codify\Controllers;

use Codify\Core\HttpException;
use Codify\Core\Request;
use Codify\Core\Response;
use Codify\Repositories\ProblemBankRepository;
use Codify\Repositories\SystemSettingRepository;
use Codify\Services\AuthGuard;
use Codify\Support\Validator;

final class ProblemBankController
{
    private $problems;
    private $settings;
    private $guard;

    public function __construct(ProblemBankRepository $problems, SystemSettingRepository $settings, AuthGuard $guard)
    { $this->problems = $problems; $this->settings = $settings; $this->guard = $guard; }

    public function index(Request $request): void
    {
        $faculty = $this->faculty($request); $settings = $this->settings->current();
        $filters = $this->filters($request);
        Response::success([
            'problems' => $this->problems->problems((int) $faculty['id'], $filters),
            'offerings' => $this->problems->offerings((int) $faculty['id'], $settings['academic_year'], $settings['academic_term']),
            'metrics' => $this->problems->metrics((int) $faculty['id']),
            'filters' => $filters,
        ]);
    }

    public function show(Request $request): void
    { $faculty = $this->faculty($request); Response::success($this->problems->problem((int) $faculty['id'], $this->problemId($request))); }

    public function store(Request $request): void
    {
        $faculty = $this->faculty($request); $payload = $this->payload($request->json()); $settings = $this->settings->current();
        $problem = $this->problems->create((int) $faculty['id'], $payload['problem'], $payload['subject_ids'], $payload['test_cases'], $settings['academic_year'], $settings['academic_term']);
        Response::success($problem, 'Python problem created.', 201);
    }

    public function update(Request $request): void
    {
        $faculty = $this->faculty($request); $payload = $this->payload($request->json()); $settings = $this->settings->current();
        $problem = $this->problems->update((int) $faculty['id'], $this->problemId($request), $payload['problem'], $payload['subject_ids'], $payload['test_cases'], $settings['academic_year'], $settings['academic_term']);
        Response::success($problem, 'Python problem updated.');
    }

    public function destroy(Request $request): void
    { $faculty = $this->faculty($request); $this->problems->delete((int) $faculty['id'], $this->problemId($request)); Response::success([], 'Python problem deleted.'); }

    private function filters(Request $request): array
    {
        $difficulty = strtolower(trim((string) $request->query('difficulty', '')));
        if (!in_array($difficulty, ['', 'beginner', 'intermediate', 'advanced'], true)) $difficulty = '';
        $status = strtolower(trim((string) $request->query('status', '')));
        if (!in_array($status, ['', 'active', 'inactive'], true)) $status = '';
        $offering = filter_var($request->query('offering_id', 0), FILTER_VALIDATE_INT);
        return ['search' => trim((string) $request->query('search', '')), 'difficulty' => $difficulty, 'status' => $status, 'offering_id' => $offering === false || $offering < 1 ? 0 : (int) $offering];
    }

    private function payload(array $input): array
    {
        $validator = new Validator($input);
        $code = strtoupper($validator->requiredString('code', 50));
        $title = $validator->requiredString('title', 200);
        $language = $validator->oneOf('language', ['python'], 'python');
        $difficulty = $validator->oneOf('difficulty', ['beginner', 'intermediate', 'advanced'], 'beginner');
        $statement = $validator->requiredString('problem_statement', 30000);
        $inputFormat = $validator->optionalString('input_format', 10000);
        $outputFormat = $validator->optionalString('output_format', 10000);
        $constraints = $validator->optionalString('constraints_text', 10000);
        $starterCode = $validator->optionalString('starter_code', 60000);
        $referenceSolution = $validator->optionalString('reference_solution', 60000);
        $solutionNotes = $validator->optionalString('solution_notes', 30000);
        $tags = $validator->optionalString('tags', 500);
        $timeLimit = $validator->integer('time_limit_ms', 100, 10000, 2000);
        $memoryLimit = $validator->integer('memory_limit_mb', 16, 1024, 128);
        $active = $validator->boolean('is_active', true);
        if ($code !== '' && preg_match('/^[A-Z0-9._-]+$/', $code) !== 1) $validator->add('code', 'The problem code may contain only letters, numbers, dots, underscores, and hyphens.');
        $subjectIds = $this->positiveIds($input['faculty_subject_ids'] ?? null, $validator);
        $testCases = $this->testCases($input['test_cases'] ?? null, $validator);
        if ($active && count($testCases) < 1) $validator->add('test_cases', 'An active problem requires at least one test case.');
        $validator->throwIfFailed();
        return ['problem' => ['code' => $code, 'title' => $title, 'language' => $language, 'difficulty' => $difficulty, 'problem_statement' => $statement, 'input_format' => $inputFormat, 'output_format' => $outputFormat, 'constraints_text' => $constraints, 'starter_code' => $starterCode, 'reference_solution' => $referenceSolution, 'solution_notes' => $solutionNotes, 'tags' => $tags, 'time_limit_ms' => $timeLimit, 'memory_limit_mb' => $memoryLimit, 'is_active' => $active], 'subject_ids' => $subjectIds, 'test_cases' => $testCases];
    }

    private function positiveIds($value, Validator $validator): array
    {
        if (!is_array($value) || $value === []) { $validator->add('faculty_subject_ids', 'Select at least one subject from your teaching load.'); return []; }
        $ids = [];
        foreach ($value as $item) { $id = filter_var($item, FILTER_VALIDATE_INT); if ($id === false || $id < 1) { $validator->add('faculty_subject_ids', 'A selected subject is invalid.'); continue; } $ids[(int) $id] = (int) $id; }
        return array_values($ids);
    }

    private function testCases($value, Validator $validator): array
    {
        if ($value === null || $value === []) return [];
        if (!is_array($value)) { $validator->add('test_cases', 'Test cases must be supplied as a list.'); return []; }
        if (count($value) > 50) { $validator->add('test_cases', 'A problem is limited to 50 test cases.'); return []; }
        $cases = [];
        foreach ($value as $index => $case) {
            if (!is_array($case)) { $validator->add('test_cases', 'Test case ' . ($index + 1) . ' is invalid.'); continue; }
            $input = $this->normalizedText($case['input_data'] ?? ''); $expected = $this->normalizedText($case['expected_output'] ?? '');
            if ($this->length($input) > 20000) $validator->add('test_cases', 'Test case ' . ($index + 1) . ' input is too long.');
            if ($this->length($expected) > 20000) $validator->add('test_cases', 'Test case ' . ($index + 1) . ' expected output is too long.');
            $sample = filter_var($case['is_sample'] ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($sample === null) { $validator->add('test_cases', 'Test case ' . ($index + 1) . ' visibility is invalid.'); $sample = false; }
            $points = filter_var($case['points'] ?? 1, FILTER_VALIDATE_INT);
            if ($points === false || $points < 0 || $points > 1000) { $validator->add('test_cases', 'Test case ' . ($index + 1) . ' points must be from 0 to 1000.'); $points = 1; }
            $cases[] = ['input_data' => $input, 'expected_output' => $expected, 'is_sample' => (bool) $sample, 'points' => (int) $points];
        }
        return $cases;
    }

    private function normalizedText(string $value): string { return str_replace(["\r\n", "\r"], "\n", $value); }
    private function length(string $value): int { return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value); }
    private function faculty(Request $request): array { return $this->guard->authenticate($request, true, 'faculty'); }
    private function problemId(Request $request): int { $id = filter_var($request->route('problem'), FILTER_VALIDATE_INT); if ($id === false || $id < 1) throw new HttpException(404, 'Python problem not found.'); return (int) $id; }
}
