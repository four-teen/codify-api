<?php
declare(strict_types=1);

namespace Codify\Controllers;

use Codify\Core\HttpException;
use Codify\Core\Request;
use Codify\Core\Response;
use Codify\Repositories\FacultyTeachingRepository;
use Codify\Repositories\SystemSettingRepository;
use Codify\Repositories\TokenRepository;
use Codify\Repositories\UserRepository;
use Codify\Services\AuthGuard;
use Codify\Support\Validator;
use Codify\Services\SyllabusStorageService;

final class FacultyTeachingController
{
    private $teaching;
    private $users;
    private $tokens;
    private $settings;
    private $guard;
    private $syllabus;

    public function __construct(FacultyTeachingRepository $teaching, UserRepository $users, TokenRepository $tokens, SystemSettingRepository $settings, AuthGuard $guard, SyllabusStorageService $syllabus)
    { $this->teaching = $teaching; $this->users = $users; $this->tokens = $tokens; $this->settings = $settings; $this->guard = $guard; $this->syllabus = $syllabus; }

    public function index(Request $request): void
    {
        $faculty = $this->faculty($request); $settings = $this->settings->current();
        Response::success($this->teaching->offerings((int) $faculty['id'], $settings['academic_year'], $settings['academic_term']));
    }

    public function store(Request $request): void
    {
        $faculty = $this->faculty($request); $input = $request->json(); $v = new Validator($input);
        $subjectId = $v->integer('subject_id', 1, PHP_INT_MAX, 0); $section = $v->optionalString('section', 100); $schedule = $v->optionalString('class_schedule', 255); $v->throwIfFailed();
        $section = $this->upper($section === null ? '' : $section); $schedule = $schedule === null ? null : $this->upper($schedule);
        $settings = $this->settings->current();
        $offering = $this->teaching->createOffering((int) $faculty['id'], $subjectId, $section, $schedule, $settings['academic_year'], $settings['academic_term']);
        Response::success($offering, 'Subject added to your teaching list.', 201);
    }

    public function show(Request $request): void
    {
        $faculty = $this->faculty($request); $id = $this->offeringId($request);
        Response::success(['offering' => $this->teaching->offering((int) $faculty['id'], $id), 'students' => $this->teaching->students((int) $faculty['id'], $id)]);
    }

    public function uploadSyllabus(Request $request): void
    {
        $faculty = $this->faculty($request); $offeringId = $this->offeringId($request);
        $previous = $this->teaching->syllabus((int) $faculty['id'], $offeringId); $stored = $this->syllabus->store($request->file('syllabus'));
        try { $offering = $this->teaching->saveSyllabus((int) $faculty['id'], $offeringId, $stored); }
        catch (\Throwable $exception) { $this->syllabus->delete($stored['stored_name']); throw $exception; }
        if ($previous !== null && $previous['stored_name'] !== $stored['stored_name']) $this->syllabus->delete($previous['stored_name']);
        Response::success($offering, $previous === null ? 'Syllabus PDF uploaded.' : 'Syllabus PDF replaced.', $previous === null ? 201 : 200);
    }

    public function showSyllabus(Request $request): void
    {
        $faculty = $this->faculty($request); $syllabus = $this->teaching->syllabus((int) $faculty['id'], $this->offeringId($request));
        if ($syllabus === null) throw new HttpException(404, 'No syllabus PDF has been uploaded for this subject.');
        $this->syllabus->stream($syllabus);
    }

    public function destroySyllabus(Request $request): void
    {
        $faculty = $this->faculty($request); $syllabus = $this->teaching->removeSyllabus((int) $faculty['id'], $this->offeringId($request));
        if ($syllabus === null) throw new HttpException(404, 'No syllabus PDF has been uploaded for this subject.');
        $this->syllabus->delete($syllabus['stored_name']); Response::success([], 'Syllabus PDF removed.');
    }

    public function destroy(Request $request): void
    {
        $faculty = $this->faculty($request); $offeringId = $this->offeringId($request); $syllabus = $this->teaching->syllabus((int) $faculty['id'], $offeringId);
        $this->teaching->deleteOffering((int) $faculty['id'], $offeringId); if ($syllabus !== null) $this->syllabus->delete($syllabus['stored_name']);
        Response::success([], 'Faculty subject and all subject-related records removed. Student accounts remain available.');
    }

    public function storeStudent(Request $request): void
    {
        $faculty = $this->faculty($request); $offeringId = $this->offeringId($request);
        $result = $this->saveStudent((int) $faculty['id'], $offeringId, $request->json(), 'manual');
        Response::success($result, $result['created'] ? 'Student account created and enrolled.' : 'Existing student enrolled in the subject.', 201);
    }

    public function importStudents(Request $request): void
    {
        $faculty = $this->faculty($request); $offeringId = $this->offeringId($request); $input = $request->json();
        $rows = $input['rows'] ?? null;
        if (!is_array($rows) || $rows === []) throw new HttpException(422, 'The class list does not contain student rows.', ['rows' => ['Choose a valid class-list file.']]);
        if (count($rows) > 500) throw new HttpException(422, 'A class-list import is limited to 500 students.');
        $results = []; $created = 0; $enrolled = 0; $existing = 0; $failed = 0;
        foreach ($rows as $index => $row) {
            $rowNumber = is_array($row) && isset($row['row_number']) ? (int) $row['row_number'] : $index + 1;
            try {
                if (!is_array($row)) throw new HttpException(422, 'The class-list row is invalid.');
                $result = $this->saveStudent((int) $faculty['id'], $offeringId, $row, 'import');
                if ($result['created']) $created++;
                elseif ($result['enrolled']) $enrolled++;
                else $existing++;
                $results[] = [
                    'row_number' => $rowNumber, 'name' => $result['student']['name'],
                    'status' => $result['created'] ? 'created' : ($result['enrolled'] ? 'enrolled' : 'already_enrolled'),
                    'username' => $result['student']['username'], 'email' => $result['student']['email'],
                    'temporary_password' => $result['credentials']['temporary_password'] ?? null,
                    'warning' => $result['email_discrepancy'], 'error' => null,
                ];
            } catch (HttpException $exception) {
                $failed++;
                $results[] = ['row_number' => $rowNumber, 'name' => is_array($row) ? (string) ($row['full_name'] ?? '') : '', 'status' => 'failed', 'username' => null, 'email' => null, 'temporary_password' => null, 'warning' => null, 'error' => $exception->getMessage()];
            }
        }
        Response::success(['summary' => ['total' => count($rows), 'created' => $created, 'enrolled' => $enrolled, 'already_enrolled' => $existing, 'failed' => $failed], 'rows' => $results], 'Class list import completed.');
    }

    public function destroyStudent(Request $request): void
    {
        $faculty = $this->faculty($request); $this->teaching->unenroll((int) $faculty['id'], $this->offeringId($request), $this->studentId($request));
        Response::success([], 'Student removed from this subject. The account remains available for other subjects.');
    }

    public function studentMonitoring(Request $request): void
    {
        $faculty = $this->faculty($request);
        Response::success($this->teaching->studentMonitoring(
            (int) $faculty['id'],
            $this->offeringId($request),
            $this->studentId($request)
        ));
    }

    public function studentAssessmentRetakes(Request $request): void
    {
        $faculty = $this->faculty($request);
        Response::success($this->teaching->studentAssessmentRetakes(
            (int) $faculty['id'],
            $this->offeringId($request),
            $this->studentId($request)
        ));
    }

    public function grantStudentAssessmentRetake(Request $request): void
    {
        $faculty = $this->faculty($request);
        Response::success($this->teaching->grantStudentAssessmentRetake(
            (int) $faculty['id'],
            $this->offeringId($request),
            $this->studentId($request),
            $this->assessmentId($request)
        ), 'One assessment retake allowed for this student.');
    }

    public function resetStudentPassword(Request $request): void
    {
        $faculty = $this->faculty($request);
        $offeringId = $this->offeringId($request);
        $studentId = $this->studentId($request);
        $student = $this->teaching->student((int) $faculty['id'], $offeringId, $studentId);
        $temporaryPassword = $this->temporaryPassword();
        $this->teaching->transaction(function () use ($studentId, $temporaryPassword): void {
            $this->users->setTemporaryPassword($studentId, $this->hash($temporaryPassword));
            $this->tokens->revokeAll($studentId);
        });
        Response::success([
            'credentials' => [
                'username' => $student['username'],
                'email' => $student['email'],
                'temporary_password' => $temporaryPassword,
            ],
        ], 'Student password reset. Existing sessions were revoked.');
    }

    public function destroyStudents(Request $request): void
    {
        $faculty = $this->faculty($request);
        $removed = $this->teaching->unenrollAll((int) $faculty['id'], $this->offeringId($request));
        $label = $removed === 1 ? 'student' : 'students';
        Response::success(['removed_count' => $removed], $removed . ' ' . $label . ' removed from this subject. Their accounts remain available for other subjects.');
    }

    private function saveStudent(int $facultyId, int $offeringId, array $input, string $source): array
    {
        $offering = $this->teaching->offering($facultyId, $offeringId); $data = $this->studentData($input);
        return $this->teaching->transaction(function () use ($facultyId, $offeringId, $offering, $data, $source) {
            $existing = $this->users->findStudentByNumber($data['student_number']);
            $created = false; $temporaryPassword = null; $emailDiscrepancy = null;
            if ($existing) {
                if ((int) $existing['faculty_id'] !== $facultyId) throw new HttpException(422, 'This student number belongs to an account managed by another faculty member. Ask an administrator to review the account assignment.');
                $profile = $this->teaching->studentProfile((int) $existing['id']);
                if ($profile && (int) $profile['program_id'] !== (int) $offering['program_id']) throw new HttpException(422, 'This student already belongs to a different program.');
                if ($data['email'] !== null && strcasecmp((string) $existing['email'], $data['email']) !== 0) {
                    $emailDiscrepancy = 'The class-list email differs from the existing account. The registered email was kept unchanged.';
                }
                $student = $existing;
            } else {
                $credentialLocal = $this->credentialLocal($data['first_name'], $data['last_name']);
                if ($data['email'] !== null) {
                    $email = $data['email'];
                    $temporaryPassword = $credentialLocal . '@1234';
                } else {
                    $local = $this->uniqueEmailLocal($credentialLocal);
                    $email = $local . '@sksu.edu.ph'; $temporaryPassword = $local . '@1234';
                }
                $this->users->assertUnique($data['student_number'], $email, null);
                $student = $this->users->create([
                    'faculty_id' => $facultyId, 'first_name' => $data['first_name'], 'last_name' => $data['last_name'],
                    'name' => trim($data['first_name'] . ' ' . $data['last_name']), 'username' => $data['student_number'],
                    'email' => $email, 'password' => $this->hash($temporaryPassword), 'role' => 'student',
                    'is_active' => $data['is_active'], 'must_change_password' => true,
                ]);
                $created = true;
            }
            $this->teaching->syncStudentProfile((int) $student['id'], (int) $offering['program_id'], $data);
            $enrolled = $this->teaching->enroll($facultyId, $offeringId, (int) $student['id'], $source);
            return [
                'created' => $created, 'enrolled' => $enrolled, 'student' => $this->teaching->student($facultyId, $offeringId, (int) $student['id']),
                'credentials' => $created ? ['username' => $student['username'], 'email' => $student['email'], 'temporary_password' => $temporaryPassword] : null,
                'email_discrepancy' => $emailDiscrepancy,
            ];
        });
    }

    private function studentData(array $input): array
    {
        if (isset($input['full_name']) && trim((string) $input['full_name']) !== '') {
            list($firstName, $lastName) = $this->splitFullName((string) $input['full_name']);
            $input['first_name'] = $firstName; $input['last_name'] = $lastName;
        }
        if (!isset($input['student_number']) && isset($input['code'])) $input['student_number'] = $input['code'];
        if (!isset($input['mobile_number']) && isset($input['mobilenumber'])) $input['mobile_number'] = $input['mobilenumber'];
        if (!isset($input['course_label']) && isset($input['course'])) $input['course_label'] = $input['course'];
        if (!isset($input['enrollment_status']) && isset($input['status'])) $input['enrollment_status'] = $input['status'];
        if (!isset($input['email']) && isset($input['source_email'])) $input['email'] = $input['source_email'];
        $v = new Validator($input); $firstName = $v->requiredString('first_name', 100); $lastName = $v->requiredString('last_name', 100);
        $studentNumber = preg_replace('/\s+/', '', $v->requiredString('student_number', 50));
        $gender = $v->optionalString('gender', 30); $mobile = $v->optionalString('mobile_number', 30);
        $course = $v->optionalString('course_label', 255); $status = $v->optionalString('enrollment_status', 100);
        $email = $v->optionalString('email', 255);
        if ($email !== null) {
            $email = strtolower($email);
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) $v->add('email', 'The class-list email must be a valid email address.');
        }
        $active = $v->boolean('is_active', true); $v->throwIfFailed();
        if ($studentNumber === '' || preg_match('/^[A-Za-z0-9._-]+$/', $studentNumber) !== 1) throw new HttpException(422, 'The student Code must contain only letters, numbers, dots, underscores, or hyphens.');
        return ['first_name' => $firstName, 'last_name' => $lastName, 'student_number' => $studentNumber, 'email' => $email, 'gender' => $gender, 'mobile_number' => $mobile, 'course_label' => $course, 'enrollment_status' => $status, 'is_active' => $active];
    }

    private function splitFullName(string $fullName): array
    {
        $fullName = trim($fullName);
        if (strpos($fullName, ',') !== false) {
            $parts = explode(',', $fullName, 2); $lastName = trim($parts[0]); $firstName = trim($parts[1]);
        } else {
            $parts = preg_split('/\s+/', $fullName) ?: []; $lastName = count($parts) > 1 ? array_pop($parts) : ''; $firstName = trim(implode(' ', $parts));
        }
        if ($firstName === '' || $lastName === '') throw new HttpException(422, 'Use the class-list name format LASTNAME, GIVEN NAMES.');
        return [$firstName, $lastName];
    }

    private function uniqueEmailLocal(string $base): string
    {
        for ($suffix = 1; $suffix <= 9999; $suffix++) {
            $local = $suffix === 1 ? $base : $base . $suffix;
            if (!$this->users->findByEmail($local . '@sksu.edu.ph')) return $local;
        }
        throw new HttpException(422, 'A unique institutional email could not be generated for this student.');
    }

    private function credentialLocal(string $firstName, string $lastName): string
    {
        $name = $firstName . $lastName;
        if (function_exists('iconv')) { $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name); if ($ascii !== false) $name = $ascii; }
        $local = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '', $name));
        if ($local === '') throw new HttpException(422, 'The supplied name cannot generate institutional credentials.');
        return $local;
    }

    private function faculty(Request $request): array { return $this->guard->authenticate($request, true, 'faculty', true); }
    private function offeringId(Request $request): int { return $this->routeId($request, 'offering', 'Faculty subject not found.'); }
    private function studentId(Request $request): int { return $this->routeId($request, 'student', 'Student not found.'); }
    private function assessmentId(Request $request): int { return $this->routeId($request, 'assessment', 'Quiz not found.'); }
    private function routeId(Request $request, string $key, string $message): int { $id = filter_var($request->route($key), FILTER_VALIDATE_INT); if ($id === false || $id < 1) throw new HttpException(404, $message); return (int) $id; }
    private function upper(string $value): string { return function_exists('mb_strtoupper') ? mb_strtoupper(trim($value), 'UTF-8') : strtoupper(trim($value)); }
    private function hash(string $password): string { return password_hash($password, PASSWORD_BCRYPT, ['cost' => max(10, min(14, (int) env('BCRYPT_ROUNDS', '12')))]); }
    private function temporaryPassword(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
        $password = 'Cd9!';
        for ($index = 0; $index < 10; $index++) $password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        return $password;
    }
}
