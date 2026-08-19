<?php
declare(strict_types=1);

namespace Codify\Controllers;

use Codify\Core\HttpException;
use Codify\Core\Request;
use Codify\Core\Response;
use Codify\Repositories\StudentLearningRepository;
use Codify\Repositories\SystemSettingRepository;
use Codify\Services\AuthGuard;
use Codify\Services\CodeExecutionRateLimiter;
use Codify\Services\Judge0RunnerService;
use Codify\Services\SyllabusStorageService;
use Codify\Support\Validator;

final class StudentLearningController
{
    private $learning;
    private $settings;
    private $guard;
    private $executionLimiter;
    private $runner;
    private $executionLimit;
    private $syllabus;

    public function __construct(StudentLearningRepository $learning, SystemSettingRepository $settings, AuthGuard $guard, CodeExecutionRateLimiter $executionLimiter, Judge0RunnerService $runner, int $executionLimit, SyllabusStorageService $syllabus)
    { $this->learning = $learning; $this->settings = $settings; $this->guard = $guard; $this->executionLimiter = $executionLimiter; $this->runner = $runner; $this->executionLimit = $executionLimit; $this->syllabus = $syllabus; }

    public function overview(Request $request): void
    {
        $student = $this->student($request); $term = $this->term();
        Response::success($this->learning->overview((int) $student['id'], $term['academic_year'], $term['academic_term']));
    }

    public function subjects(Request $request): void
    {
        $student = $this->student($request); $term = $this->term();
        Response::success($this->learning->subjects((int) $student['id'], $term['academic_year'], $term['academic_term']));
    }

    public function showSubject(Request $request): void
    {
        $student = $this->student($request); $term = $this->term();
        Response::success($this->learning->subjectDashboard((int) $student['id'], $this->subjectId($request), $term['academic_year'], $term['academic_term']));
    }

    public function showSyllabus(Request $request): void
    {
        $student = $this->student($request); $term = $this->term();
        $syllabus = $this->learning->syllabus((int) $student['id'], $this->subjectId($request), $term['academic_year'], $term['academic_term']);
        $this->syllabus->stream($syllabus);
    }

    public function showAssessment(Request $request): void
    {
        $student = $this->student($request); $term = $this->term();
        Response::success($this->learning->assessment(
            (int) $student['id'], $this->subjectId($request), $this->assessmentId($request),
            $term['academic_year'], $term['academic_term']
        ));
    }

    public function submitAssessment(Request $request): void
    {
        $student = $this->student($request); $term = $this->term(); $input = $request->json();
        $answers = $input['answers'] ?? null;
        if (!is_array($answers)) throw new HttpException(422, 'Assessment answers must be supplied as a list.');
        Response::success($this->learning->submitAssessment(
            (int) $student['id'], $this->subjectId($request), $this->assessmentId($request),
            $term['academic_year'], $term['academic_term'], $answers
        ), 'Assessment submitted successfully.', 201);
    }

    public function problems(Request $request): void
    {
        $student = $this->student($request); $term = $this->term();
        Response::success($this->learning->problems((int) $student['id'], $term['academic_year'], $term['academic_term'], $this->filters($request)));
    }

    public function showProblem(Request $request): void
    {
        $student = $this->student($request); $term = $this->term();
        Response::success($this->learning->problem((int) $student['id'], $this->problemId($request), $term['academic_year'], $term['academic_term']));
    }

    public function runProblem(Request $request): void
    {
        $student = $this->student($request); $term = $this->term(); $input = $request->json();
        $validator = new Validator($input);
        $code = $this->normalizedText((string) ($input['code'] ?? ''));
        $stdin = $this->normalizedText((string) ($input['stdin'] ?? ''));
        if (trim($code) === '') $validator->add('code', 'Enter Python code before running.');
        if ($this->length($code) > 60000) $validator->add('code', 'Python code may not exceed 60,000 characters.');
        if ($this->length($stdin) > 20000) $validator->add('stdin', 'Program input may not exceed 20,000 characters.');
        $validator->throwIfFailed();
        $problem = $this->learning->problem((int) $student['id'], $this->problemId($request), $term['academic_year'], $term['academic_term']);
        $this->executionLimiter->assertAllowed((int) $student['id'], $request->ip(), $this->executionLimit);
        $this->executionLimiter->hit((int) $student['id'], $request->ip());
        $result = $this->runner->execute($code, $stdin, (int) $problem['time_limit_ms'], (int) $problem['memory_limit_mb']);
        Response::success($result, 'Python execution completed.');
    }

    private function filters(Request $request): array
    {
        $difficulty = strtolower(trim((string) $request->query('difficulty', '')));
        if (!in_array($difficulty, ['', 'beginner', 'intermediate', 'advanced'], true)) $difficulty = '';
        $offering = filter_var($request->query('offering_id', 0), FILTER_VALIDATE_INT);
        return ['search' => substr(trim((string) $request->query('search', '')), 0, 200),
            'difficulty' => $difficulty, 'offering_id' => $offering === false || $offering < 1 ? 0 : (int) $offering];
    }

    private function term(): array
    {
        $settings = $this->settings->current();
        return ['academic_year' => $settings['academic_year'], 'academic_term' => $settings['academic_term']];
    }

    private function student(Request $request): array { return $this->guard->authenticate($request, true, 'student'); }
    private function normalizedText(string $value): string { return str_replace(["\r\n", "\r"], "\n", $value); }
    private function length(string $value): int { return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value); }
    private function subjectId(Request $request): int
    {
        $id = filter_var($request->route('subject'), FILTER_VALIDATE_INT);
        if ($id === false || $id < 1) throw new HttpException(404, 'Subject not found in your current workspace.');
        return (int) $id;
    }
    private function problemId(Request $request): int
    {
        $id = filter_var($request->route('problem'), FILTER_VALIDATE_INT);
        if ($id === false || $id < 1) throw new HttpException(404, 'Python problem not found.');
        return (int) $id;
    }
    private function assessmentId(Request $request): int
    {
        $id = filter_var($request->route('assessment'), FILTER_VALIDATE_INT);
        if ($id === false || $id < 1) throw new HttpException(404, 'Assessment not found.');
        return (int) $id;
    }
}
