<?php
declare(strict_types=1);

return [
    'workspaces' => [
        'administrator' => ['dashboard', 'user_management', 'faculty_management', 'academic_structure', 'system_settings', 'compiler_configuration', 'fingerprint_configuration', 'risk_scoring', 'audit_logs', 'research_export'],
        'student' => ['dashboard', 'courses', 'coding_exercises', 'assignments', 'online_compiler', 'assessments', 'submission_history', 'progress_tracking'],
        'faculty' => ['dashboard', 'course_management', 'problem_bank', 'assignment_management', 'quiz_builder', 'student_analytics', 'integrity_monitoring', 'integrity_reports'],
    ],
];
