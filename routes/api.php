<?php
declare(strict_types=1);

use Codify\Core\Request;
use Codify\Core\Response;

return static function ($router, array $controllers): void {
    $prefix = '/api/v1';
    $router->get($prefix, static function (Request $request): void {
        Response::success(['name' => 'Codify API', 'version' => 'v1', 'status' => 'online']);
    });
    $router->get($prefix . '/system-settings/public', [$controllers['settings'], 'publicShow']);
    $router->post($prefix . '/auth/login', [$controllers['auth'], 'login']);
    $router->get($prefix . '/auth/me', [$controllers['auth'], 'me']);
    $router->post($prefix . '/auth/logout', [$controllers['auth'], 'logout']);
    $router->patch($prefix . '/auth/password', [$controllers['auth'], 'changePassword']);

    foreach (['student', 'faculty', 'administrator'] as $role) {
        $router->get($prefix . '/' . $role . '/workspace', static function (Request $request) use ($controllers, $role): void {
            $controllers['workspace']->show($request, $role);
        });
    }

    $router->get($prefix . '/system-settings', [$controllers['settings'], 'show']);
    $router->patch($prefix . '/system-settings', [$controllers['settings'], 'update']);
    $router->get($prefix . '/admin/data-cleanup', [$controllers['administrator_data_cleanup'], 'show']);
    $router->post($prefix . '/admin/data-cleanup', [$controllers['administrator_data_cleanup'], 'clear']);

    $router->get($prefix . '/users', [$controllers['users'], 'index']);
    $router->post($prefix . '/users', [$controllers['users'], 'store']);
    $router->get($prefix . '/users/{user}', [$controllers['users'], 'show']);
    $router->patch($prefix . '/users/{user}', [$controllers['users'], 'update']);
    $router->delete($prefix . '/users/{user}', [$controllers['users'], 'destroy']);

    $router->get($prefix . '/admin/faculty', [$controllers['faculty_management'], 'index']);
    $router->post($prefix . '/admin/faculty', [$controllers['faculty_management'], 'store']);
    $router->get($prefix . '/admin/faculty/{faculty}/dashboard', [$controllers['faculty_management'], 'dashboard']);
    $router->delete($prefix . '/admin/faculty/{faculty}/students', [$controllers['faculty_management'], 'destroyStudents']);
    $router->delete($prefix . '/admin/faculty/{faculty}/subjects', [$controllers['faculty_management'], 'destroySubjects']);
    $router->delete($prefix . '/admin/faculty/{faculty}/subjects/{offering}', [$controllers['faculty_management'], 'destroySubject']);
    $router->get($prefix . '/admin/faculty/{faculty}', [$controllers['faculty_management'], 'show']);
    $router->patch($prefix . '/admin/faculty/{faculty}', [$controllers['faculty_management'], 'update']);
    $router->delete($prefix . '/admin/faculty/{faculty}', [$controllers['faculty_management'], 'destroy']);

    $router->get($prefix . '/admin/student-audit', [$controllers['administrator_student_audit'], 'index']);
    $router->get($prefix . '/admin/student-audit/{student}', [$controllers['administrator_student_audit'], 'show']);
    $router->delete($prefix . '/admin/student-audit/{student}/login-events', [$controllers['administrator_student_audit'], 'clearLoginEvents']);
    $router->delete($prefix . '/admin/student-audit/{student}/devices', [$controllers['administrator_student_audit'], 'resetDevices']);
    $router->delete($prefix . '/admin/student-audit/{student}/devices/{device}', [$controllers['administrator_student_audit'], 'destroyDevice']);
    $router->post($prefix . '/admin/student-audit/{student}/revoke-sessions', [$controllers['administrator_student_audit'], 'revokeSessions']);

    $router->get($prefix . '/faculty/subjects', [$controllers['faculty_subjects'], 'index']);
    $router->get($prefix . '/faculty/problem-bank', [$controllers['problem_bank'], 'index']);
    $router->get($prefix . '/faculty/rubric-templates', [$controllers['problem_rubrics'], 'templates']);
    $router->post($prefix . '/faculty/rubric-templates', [$controllers['problem_rubrics'], 'saveTemplate']);
    $router->patch($prefix . '/faculty/rubric-templates/{template}', [$controllers['problem_rubrics'], 'saveTemplate']);
    $router->delete($prefix . '/faculty/rubric-templates/{template}', [$controllers['problem_rubrics'], 'deleteTemplate']);
    $router->post($prefix . '/faculty/problem-bank/{problem}/responses/{student}/initial-score', [$controllers['problem_rubrics'], 'generate']);
    $router->put($prefix . '/faculty/problem-bank/{problem}/responses/{student}/final-score', [$controllers['problem_rubrics'], 'finalize']);
    $router->post($prefix . '/faculty/problem-bank', [$controllers['problem_bank'], 'store']);
    $router->get($prefix . '/faculty/problem-bank/{problem}', [$controllers['problem_bank'], 'show']);
    $router->patch($prefix . '/faculty/problem-bank/{problem}', [$controllers['problem_bank'], 'update']);
    $router->delete($prefix . '/faculty/problem-bank/{problem}', [$controllers['problem_bank'], 'destroy']);
    $router->get($prefix . '/faculty/assessment-bank', [$controllers['assessment_bank'], 'index']);
    $router->post($prefix . '/faculty/assessment-bank', [$controllers['assessment_bank'], 'store']);
    $router->get($prefix . '/faculty/assessment-bank/{bank}/responses', [$controllers['assessment_bank'], 'responses']);
    $router->post($prefix . '/faculty/assessment-bank/{bank}/retakes', [$controllers['assessment_bank'], 'grantRetakes']);
    $router->get($prefix . '/faculty/assessment-bank/{bank}', [$controllers['assessment_bank'], 'show']);
    $router->patch($prefix . '/faculty/assessment-bank/{bank}', [$controllers['assessment_bank'], 'update']);
    $router->delete($prefix . '/faculty/assessment-bank/{bank}', [$controllers['assessment_bank'], 'destroy']);

    $router->get($prefix . '/student/learning', [$controllers['student_learning'], 'overview']);
    $router->get($prefix . '/student/subjects', [$controllers['student_learning'], 'subjects']);
    $router->get($prefix . '/student/subjects/{subject}', [$controllers['student_learning'], 'showSubject']);
    $router->get($prefix . '/student/subjects/{subject}/syllabus', [$controllers['student_learning'], 'showSyllabus']);
    $router->get($prefix . '/student/subjects/{subject}/assessments/{assessment}', [$controllers['student_learning'], 'showAssessment']);
    $router->post($prefix . '/student/subjects/{subject}/assessments/{assessment}/submit', [$controllers['student_learning'], 'submitAssessment']);
    $router->get($prefix . '/student/problems', [$controllers['student_learning'], 'problems']);
    $router->get($prefix . '/student/problems/{problem}', [$controllers['student_learning'], 'showProblem']);
    $router->get($prefix . '/student/problems/{problem}/work', [$controllers['problem_work'], 'state']);
    $router->post($prefix . '/student/problems/{problem}/work/{action}', [$controllers['problem_work'], 'act']);
    $router->get($prefix . '/faculty/problem-bank/{problem}/responses', [$controllers['problem_work'], 'responses']);
    $router->get($prefix . '/student/device-consistency', [$controllers['student_devices'], 'overview']);
    $router->post($prefix . '/student/device-consistency/consent', [$controllers['student_devices'], 'consent']);
    $router->post($prefix . '/student/device-consistency/decline', [$controllers['student_devices'], 'decline']);
    $router->delete($prefix . '/student/device-consistency/consent', [$controllers['student_devices'], 'withdraw']);
    $router->post($prefix . '/student/device-consistency/register', [$controllers['student_devices'], 'register']);
    $router->post($prefix . '/student/device-consistency/observe', [$controllers['student_devices'], 'observe']);
    $router->post($prefix . '/student/device-consistency/challenge', [$controllers['student_devices'], 'challenge']);
    $router->post($prefix . '/student/device-consistency/verify', [$controllers['student_devices'], 'verify']);
    $router->patch($prefix . '/student/devices/{device}/recognize', [$controllers['student_devices'], 'recognize']);
    $router->post($prefix . '/student/devices/{device}/report', [$controllers['student_devices'], 'report']);
    $router->delete($prefix . '/student/devices/{device}', [$controllers['student_devices'], 'destroy']);
    $router->get($prefix . '/faculty/subject-offerings', [$controllers['faculty_teaching'], 'index']);
    $router->post($prefix . '/faculty/subject-offerings', [$controllers['faculty_teaching'], 'store']);
    $router->post($prefix . '/faculty/subject-offerings/{offering}/syllabus', [$controllers['faculty_teaching'], 'uploadSyllabus']);
    $router->get($prefix . '/faculty/subject-offerings/{offering}/syllabus', [$controllers['faculty_teaching'], 'showSyllabus']);
    $router->delete($prefix . '/faculty/subject-offerings/{offering}/syllabus', [$controllers['faculty_teaching'], 'destroySyllabus']);
    $router->post($prefix . '/faculty/subject-offerings/{offering}/students/import', [$controllers['faculty_teaching'], 'importStudents']);
    $router->post($prefix . '/faculty/subject-offerings/{offering}/students', [$controllers['faculty_teaching'], 'storeStudent']);
    $router->get($prefix . '/faculty/subject-offerings/{offering}/students/{student}/monitoring', [$controllers['faculty_teaching'], 'studentMonitoring']);
    $router->get($prefix . '/faculty/subject-offerings/{offering}/students/{student}/view', [$controllers['faculty_student_view'], 'show']);
    $router->get($prefix . '/faculty/subject-offerings/{offering}/students/{student}/view/problems/{problem}', [$controllers['faculty_student_view'], 'problem']);
    $router->get($prefix . '/faculty/subject-offerings/{offering}/students/{student}/view/assessments/{assessment}', [$controllers['faculty_student_view'], 'assessment']);
    $router->get($prefix . '/faculty/subject-offerings/{offering}/students/{student}/view/syllabus', [$controllers['faculty_student_view'], 'syllabus']);
    $router->get($prefix . '/faculty/subject-offerings/{offering}/students/{student}/assessment-retakes', [$controllers['faculty_teaching'], 'studentAssessmentRetakes']);
    $router->post($prefix . '/faculty/subject-offerings/{offering}/students/{student}/assessment-retakes/{assessment}', [$controllers['faculty_teaching'], 'grantStudentAssessmentRetake']);
    $router->post($prefix . '/faculty/subject-offerings/{offering}/students/{student}/password-reset', [$controllers['faculty_teaching'], 'resetStudentPassword']);
    $router->delete($prefix . '/faculty/subject-offerings/{offering}/students', [$controllers['faculty_teaching'], 'destroyStudents']);
    $router->delete($prefix . '/faculty/subject-offerings/{offering}/students/{student}', [$controllers['faculty_teaching'], 'destroyStudent']);
    $router->get($prefix . '/faculty/subject-offerings/{offering}/grades', [$controllers['subject_gradebook'], 'show']);
    $router->get($prefix . '/faculty/subject-offerings/{offering}/attendance/history', [$controllers['subject_attendance'], 'history']);
    $router->get($prefix . '/faculty/subject-offerings/{offering}/attendance', [$controllers['subject_attendance'], 'show']);
    $router->put($prefix . '/faculty/subject-offerings/{offering}/attendance', [$controllers['subject_attendance'], 'save']);
    $router->patch($prefix . '/faculty/subject-offerings/{offering}/grades/settings', [$controllers['subject_gradebook'], 'updateSettings']);
    $router->post($prefix . '/faculty/subject-offerings/{offering}/grades/items', [$controllers['subject_gradebook'], 'storeItem']);
    $router->patch($prefix . '/faculty/subject-offerings/{offering}/grades/items/{item}', [$controllers['subject_gradebook'], 'updateItem']);
    $router->delete($prefix . '/faculty/subject-offerings/{offering}/grades/items/{item}', [$controllers['subject_gradebook'], 'destroyItem']);
    $router->put($prefix . '/faculty/subject-offerings/{offering}/grades/scores', [$controllers['subject_gradebook'], 'saveScores']);
    $router->get($prefix . '/faculty/subject-offerings/{offering}', [$controllers['faculty_teaching'], 'show']);
    $router->delete($prefix . '/faculty/subject-offerings/{offering}', [$controllers['faculty_teaching'], 'destroy']);

    foreach (['campuses', 'colleges', 'programs', 'subjects'] as $resource) {
        $base = $prefix . '/academic/' . $resource;
        $router->get($base, static function (Request $request) use ($controllers, $resource): void { $controllers['academic']->index($request, $resource); });
        $router->post($base, static function (Request $request) use ($controllers, $resource): void { $controllers['academic']->store($request, $resource); });
        $router->get($base . '/{record}', static function (Request $request) use ($controllers, $resource): void { $controllers['academic']->show($request, $resource); });
        $router->patch($base . '/{record}', static function (Request $request) use ($controllers, $resource): void { $controllers['academic']->update($request, $resource); });
        $router->delete($base . '/{record}', static function (Request $request) use ($controllers, $resource): void { $controllers['academic']->destroy($request, $resource); });
    }
};
