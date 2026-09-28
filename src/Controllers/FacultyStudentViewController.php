<?php
declare(strict_types=1);

namespace Codify\Controllers;

use Codify\Core\HttpException;
use Codify\Core\Request;
use Codify\Core\Response;
use Codify\Repositories\FacultyTeachingRepository;
use Codify\Repositories\StudentLearningRepository;
use Codify\Repositories\SystemSettingRepository;
use Codify\Services\AuthGuard;
use Codify\Services\SyllabusStorageService;

// This controller exposes reads only and always authenticates the faculty account.
final class FacultyStudentViewController
{
    private $teaching;
    private $learning;
    private $settings;
    private $guard;
    private $syllabus;

    public function __construct(FacultyTeachingRepository $teaching, StudentLearningRepository $learning, SystemSettingRepository $settings, AuthGuard $guard, SyllabusStorageService $syllabus)
    { $this->teaching = $teaching; $this->learning = $learning; $this->settings = $settings; $this->guard = $guard; $this->syllabus = $syllabus; }

    private function id(Request $request, string $key): int
    {
        $id = filter_var($request->route($key), FILTER_VALIDATE_INT);
        if ($id === false || $id < 1) throw new HttpException(404, 'Student preview not found.');
        return (int) $id;
    }

    private function context(Request $request): array
    {
        $faculty = $this->guard->authenticate($request, true, 'faculty');
        $offeringId = $this->id($request, 'offering');
        // Recheck both ownership and enrollment on every preview request.
        $student = $this->teaching->student((int) $faculty['id'], $offeringId, $this->id($request, 'student'));
        if (!$student['is_active']) throw new HttpException(403, 'This student account is inactive and cannot access the student workspace.');
        return [$student, $offeringId, $this->settings->current()];
    }

    public function show(Request $request): void
    {
        [$student, $offeringId, $term] = $this->context($request);
        Response::success([
            'student' => ['id' => $student['id'], 'name' => $student['name'], 'student_number' => $student['student_number'],
                'must_change_password' => $student['must_change_password']],
            'dashboard' => $this->learning->subjectDashboard($student['id'], $offeringId, $term['academic_year'], $term['academic_term']),
        ]);
    }

    public function problem(Request $request): void
    {
        [$student, $offeringId, $term] = $this->context($request);
        Response::success($this->learning->problem($student['id'], $this->id($request, 'problem'), $term['academic_year'], $term['academic_term'], $offeringId));
    }

    public function assessment(Request $request): void
    {
        [$student, $offeringId, $term] = $this->context($request);
        Response::success($this->learning->assessment($student['id'], $offeringId, $this->id($request, 'assessment'), $term['academic_year'], $term['academic_term']));
    }

    public function syllabus(Request $request): void
    {
        [$student, $offeringId, $term] = $this->context($request);
        $this->syllabus->stream($this->learning->syllabus($student['id'], $offeringId, $term['academic_year'], $term['academic_term']));
    }
}
