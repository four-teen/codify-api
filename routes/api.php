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

    $router->get($prefix . '/users', [$controllers['users'], 'index']);
    $router->post($prefix . '/users', [$controllers['users'], 'store']);
    $router->get($prefix . '/users/{user}', [$controllers['users'], 'show']);
    $router->patch($prefix . '/users/{user}', [$controllers['users'], 'update']);
    $router->delete($prefix . '/users/{user}', [$controllers['users'], 'destroy']);

    $router->get($prefix . '/admin/faculty', [$controllers['faculty_management'], 'index']);
    $router->post($prefix . '/admin/faculty', [$controllers['faculty_management'], 'store']);
    $router->get($prefix . '/admin/faculty/{faculty}', [$controllers['faculty_management'], 'show']);
    $router->patch($prefix . '/admin/faculty/{faculty}', [$controllers['faculty_management'], 'update']);
    $router->delete($prefix . '/admin/faculty/{faculty}', [$controllers['faculty_management'], 'destroy']);

    $router->get($prefix . '/faculty/subjects', [$controllers['faculty_subjects'], 'index']);
    $router->get($prefix . '/faculty/problem-bank', [$controllers['problem_bank'], 'index']);
    $router->post($prefix . '/faculty/problem-bank', [$controllers['problem_bank'], 'store']);
    $router->get($prefix . '/faculty/problem-bank/{problem}', [$controllers['problem_bank'], 'show']);
    $router->patch($prefix . '/faculty/problem-bank/{problem}', [$controllers['problem_bank'], 'update']);
    $router->delete($prefix . '/faculty/problem-bank/{problem}', [$controllers['problem_bank'], 'destroy']);

    $router->get($prefix . '/student/learning', [$controllers['student_learning'], 'overview']);
    $router->get($prefix . '/student/subjects', [$controllers['student_learning'], 'subjects']);
    $router->get($prefix . '/student/subjects/{subject}', [$controllers['student_learning'], 'showSubject']);
    $router->get($prefix . '/student/problems', [$controllers['student_learning'], 'problems']);
    $router->get($prefix . '/student/problems/{problem}', [$controllers['student_learning'], 'showProblem']);
    $router->post($prefix . '/student/problems/{problem}/run', [$controllers['student_learning'], 'runProblem']);
    $router->get($prefix . '/faculty/subject-offerings', [$controllers['faculty_teaching'], 'index']);
    $router->post($prefix . '/faculty/subject-offerings', [$controllers['faculty_teaching'], 'store']);
    $router->post($prefix . '/faculty/subject-offerings/{offering}/students/import', [$controllers['faculty_teaching'], 'importStudents']);
    $router->post($prefix . '/faculty/subject-offerings/{offering}/students', [$controllers['faculty_teaching'], 'storeStudent']);
    $router->delete($prefix . '/faculty/subject-offerings/{offering}/students/{student}', [$controllers['faculty_teaching'], 'destroyStudent']);
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
