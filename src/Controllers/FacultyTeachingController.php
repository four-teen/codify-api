<?php
declare(strict_types=1);

namespace Codify\Controllers;

use Codify\Core\HttpException;
use Codify\Core\Request;
use Codify\Core\Response;
use Codify\Repositories\FacultyTeachingRepository;
use Codify\Repositories\SystemSettingRepository;
use Codify\Repositories\UserRepository;
use Codify\Services\AuthGuard;
use Codify\Support\Validator;

final class FacultyTeachingController
{
    private $teaching;
    private $users;
    private $settings;
    private $guard;

    public function __construct(FacultyTeachingRepository $teaching, UserRepository $users, SystemSettingRepository $settings, AuthGuard $guard)
    { $this->teaching = $teaching; $this->users = $users; $this->settings = $settings; $this->guard = $guard; }

    public function index(Request $request): void
    {
        $faculty = $this->faculty($request); $settings = $this->settings->current();
        Response::success($this->teaching->offerings((int) $faculty['id'], $settings['academic_year'], $settings['academic_term']));
    }

    public function store(Request $request): void
    {
        $faculty = $this->faculty($request); $input = $request->json(); $v = new Validator($input);
        $subjectId = $v->integer('subject_id', 1, PHP_INT_MAX, 0); $section = $v->optionalString('section', 100); $schedule = $v->optionalString('class_schedule', 255); $v->throwIfFailed();
        $settings = $this->settings->current();
        $offering = $this->teaching->createOffering((int) $faculty['id'], $subjectId, $section === null ? '' : $section, $schedule, $settings['academic_year'], $settings['academic_term']);
        Response::success($offering, 'Subject added to your teaching list.', 201);
    }

    public function show(Request $request): void
    {
        $faculty = $this->faculty($request); $id = $this->offeringId($request);
        Response::success(['offering' => $this->teaching->offering((int) $faculty['id'], $id), 'students' => $this->teaching->students((int) $faculty['id'], $id)]);
    }

    public function destroy(Request $request): void
    {
        $faculty = $this->faculty($request); $this->teaching->deleteOffering((int) $faculty['id'], $this->offeringId($request));
        Response::success([], 'Faculty subject removed.');
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
                    'temporary_password' => $result['credentials']['temporary_password'] ?? null, 'error' => null,
                ];
            } catch (HttpException $exception) {
                $failed++;
                $results[] = ['row_number' => $rowNumber, 'name' => is_array($row) ? (string) ($row['full_name'] ?? '') : '', 'status' => 'failed', 'username' => null, 'email' => null, 'temporary_password' => null, 'error' => $exception->getMessage()];
            }
        }
        Response::success(['summary' => ['total' => count($rows), 'created' => $created, 'enrolled' => $enrolled, 'already_enrolled' => $existing, 'failed' => $failed], 'rows' => $results], 'Class list import completed.');
    }

    public function destroyStudent(Request $request): void
    {
        $faculty = $this->faculty($request); $this->teaching->unenroll((int) $faculty['id'], $this->offeringId($request), $this->studentId($request));
        Response::success([], 'Student removed from this subject. The account remains available for other subjects.');
    }

    private function saveStudent(int $facultyId, int $offeringId, array $input, string $source): array
    {
        $offering = $this->teaching->offering($facultyId, $offeringId); $data = $this->studentData($input);
        return $this->teaching->transaction(function () use ($facultyId, $offeringId, $offering, $data, $source) {
            $existing = $this->users->findByUsername($data['student_number']);
            $created = false; $temporaryPassword = null;
            if ($existing) {
                if ($existing['role'] !== 'student' || (int) $existing['faculty_id'] !== $facultyId) throw new HttpException(422, 'The student number is already assigned to another account.');
                $profile = $this->teaching->studentProfile((int) $existing['id']);
                if ($profile && (int) $profile['program_id'] !== (int) $offering['program_id']) throw new HttpException(422, 'This student already belongs to a different program.');
                $student = $existing;
            } else {
                $local = $this->uniqueEmailLocal($this->credentialLocal($data['first_name'], $data['last_name']));
                $email = $local . '@sksu.edu.ph'; $temporaryPassword = $local . '@1234';
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
        $v = new Validator($input); $firstName = $v->requiredString('first_name', 100); $lastName = $v->requiredString('last_name', 100);
        $studentNumber = preg_replace('/\s+/', '', $v->requiredString('student_number', 50));
        $gender = $v->optionalString('gender', 30); $mobile = $v->optionalString('mobile_number', 30);
        $course = $v->optionalString('course_label', 255); $status = $v->optionalString('enrollment_status', 100);
        $active = $v->boolean('is_active', true); $v->throwIfFailed();
        if ($studentNumber === '' || preg_match('/^[A-Za-z0-9._-]+$/', $studentNumber) !== 1) throw new HttpException(422, 'The student Code must contain only letters, numbers, dots, underscores, or hyphens.');
        return ['first_name' => $firstName, 'last_name' => $lastName, 'student_number' => $studentNumber, 'gender' => $gender, 'mobile_number' => $mobile, 'course_label' => $course, 'enrollment_status' => $status, 'is_active' => $active];
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
    private function routeId(Request $request, string $key, string $message): int { $id = filter_var($request->route($key), FILTER_VALIDATE_INT); if ($id === false || $id < 1) throw new HttpException(404, $message); return (int) $id; }
    private function hash(string $password): string { return password_hash($password, PASSWORD_BCRYPT, ['cost' => max(10, min(14, (int) env('BCRYPT_ROUNDS', '12')))]); }
}
