<?php
declare(strict_types=1);

namespace Codify\Repositories;

use PDO;
use Throwable;

final class AdministratorDataCleanupRepository
{
    public const CONFIRMATION_PHRASE = 'CLEAR SELECTED DATA';

    private const DEFINITIONS = [
        'assessment_attempts' => ['label' => 'Assessment submissions', 'description' => 'Quiz and exam attempts, scores, answers, and retake permissions.'],
        'device_records' => ['label' => 'Student device records', 'description' => 'Device consent, recognized devices, challenges, sessions, and device events.'],
        'students' => ['label' => 'Student accounts', 'description' => 'Student profiles, enrollments, sessions, submissions, code runs, and device records.'],
        'assessment_banks' => ['label' => 'Quiz and exam banks', 'description' => 'Assessment banks, questions, subject links, submissions, and retake permissions.'],
        'problem_banks' => ['label' => 'Problem bank', 'description' => 'Coding problems, test cases, and subject links.'],
        'teaching_subjects' => ['label' => 'Teaching subject offerings', 'description' => 'Faculty subject offerings, rosters, syllabus files, content links, and assessment submissions.'],
        'faculty_accounts' => ['label' => 'Faculty accounts', 'description' => 'Faculty accounts and all records owned by them, including their students and teaching content.'],
        'subjects' => ['label' => 'Academic subjects', 'description' => 'Master subject records and every teaching offering based on them.'],
        'programs' => ['label' => 'Programs', 'description' => 'Programs, their subjects, assigned students, and dependent teaching data.'],
        'colleges' => ['label' => 'Colleges', 'description' => 'Colleges, programs, subjects, students, and dependent teaching data.'],
        'campuses' => ['label' => 'Campuses', 'description' => 'The complete non-administrator academic hierarchy and all dependent faculty and student data.'],
        'authentication_history' => ['label' => 'Login security history', 'description' => 'Failed-login throttle records and outstanding password-reset requests; active sessions remain signed in.'],
    ];

    private const DEPENDENCIES = [
        'students' => ['assessment_attempts', 'device_records'],
        'assessment_banks' => ['assessment_attempts'],
        'teaching_subjects' => ['assessment_attempts'],
        'faculty_accounts' => ['students', 'teaching_subjects', 'assessment_banks', 'problem_banks'],
        'subjects' => ['teaching_subjects'],
        'programs' => ['students', 'subjects'],
        'colleges' => ['programs'],
        'campuses' => ['faculty_accounts', 'colleges'],
    ];

    /** @var PDO */
    private $db;

    public function __construct(PDO $db) { $this->db = $db; }

    public function preview(): array
    {
        $counts = $this->counts();
        $categories = [];
        foreach (self::DEFINITIONS as $key => $definition) {
            $dependencies = $this->resolveCategories([$key]);
            $dependencies = array_values(array_filter($dependencies, static function (string $item) use ($key): bool { return $item !== $key; }));
            $categories[] = [
                'key' => $key,
                'label' => $definition['label'],
                'description' => $definition['description'],
                'count' => $counts[$key] ?? 0,
                'dependencies' => array_map(static function (string $item): string { return self::DEFINITIONS[$item]['label']; }, $dependencies),
            ];
        }
        return [
            'categories' => $categories,
            'confirmation_phrase' => self::CONFIRMATION_PHRASE,
            'protected_data' => ['Administrator accounts', 'System settings', 'Permissions', 'Administrator audit history'],
        ];
    }

    public function allowedCategories(): array { return array_keys(self::DEFINITIONS); }

    public function resolveCategories(array $requested): array
    {
        $resolved = [];
        $visit = function (string $category) use (&$visit, &$resolved): void {
            if (isset($resolved[$category]) || !isset(self::DEFINITIONS[$category])) return;
            $resolved[$category] = true;
            foreach (self::DEPENDENCIES[$category] ?? [] as $dependency) $visit($dependency);
        };
        foreach ($requested as $category) $visit($category);
        return array_values(array_keys($resolved));
    }

    public function clear(array $requested, int $actorId): array
    {
        $requested = array_values(array_unique($requested));
        $effective = $this->resolveCategories($requested);
        $before = $this->counts();
        $storedNames = in_array('teaching_subjects', $effective, true)
            ? array_values(array_filter($this->db->query('SELECT stored_name FROM faculty_subject_syllabi')->fetchAll(PDO::FETCH_COLUMN)))
            : [];

        $this->db->beginTransaction();
        try {
            if (in_array('device_records', $effective, true)) {
                $this->db->exec('DELETE FROM student_devices');
                $this->db->exec('DELETE FROM student_device_consents');
            }
            if (in_array('assessment_attempts', $effective, true)) {
                $this->db->exec('DELETE FROM student_assessment_retake_permissions');
                $this->db->exec('DELETE FROM student_assessment_attempts');
            }
            if (in_array('students', $effective, true)) {
                $this->db->exec("DELETE FROM password_resets WHERE email IN (SELECT email FROM users WHERE role = 'student')");
                $this->db->exec("DELETE FROM users WHERE role = 'student'");
            }
            if (in_array('assessment_banks', $effective, true)) $this->db->exec('DELETE FROM assessment_banks');
            if (in_array('problem_banks', $effective, true)) $this->db->exec('DELETE FROM coding_problems');
            if (in_array('teaching_subjects', $effective, true)) $this->db->exec('DELETE FROM faculty_subjects');
            if (in_array('faculty_accounts', $effective, true)) $this->db->exec("DELETE FROM users WHERE role = 'faculty'");
            if (in_array('subjects', $effective, true)) $this->db->exec('DELETE FROM subjects');
            if (in_array('programs', $effective, true)) {
                $this->db->exec('DELETE FROM faculty_programs');
                $this->db->exec('DELETE FROM programs');
            }
            if (in_array('colleges', $effective, true)) {
                $this->db->exec('DELETE FROM faculty_colleges');
                $this->db->exec('DELETE FROM colleges');
            }
            if (in_array('campuses', $effective, true)) $this->db->exec('DELETE FROM campuses');
            if (in_array('authentication_history', $effective, true)) {
                $this->db->exec('DELETE FROM login_attempts');
                $this->db->exec('DELETE FROM password_resets');
            }

            $deleted = [];
            foreach ($effective as $category) $deleted[$category] = $before[$category] ?? 0;
            $statement = $this->db->prepare('INSERT INTO administrator_student_audit_logs (actor_user_id, subject_user_id, action, summary, metadata, created_at) VALUES (:actor, NULL, :action, :summary, :metadata, NOW())');
            $statement->execute([
                'actor' => $actorId,
                'action' => 'administrator_data_cleanup',
                'summary' => 'Administrator cleared selected platform data categories.',
                'metadata' => json_encode(['requested' => $requested, 'effective' => $effective, 'deleted' => $deleted], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]);
            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $exception;
        }

        return [
            'requested_categories' => $requested,
            'cleared_categories' => $effective,
            'deleted' => array_intersect_key($before, array_flip($effective)),
            'stored_names' => $storedNames,
            'preview' => $this->preview(),
        ];
    }

    private function counts(): array
    {
        $count = function (string $sql): int { return (int) $this->db->query($sql)->fetchColumn(); };
        return [
            'assessment_attempts' => $count('SELECT (SELECT COUNT(*) FROM student_assessment_attempts) + (SELECT COUNT(*) FROM student_assessment_retake_permissions)'),
            'device_records' => $count('SELECT (SELECT COUNT(*) FROM student_device_consents) + (SELECT COUNT(*) FROM student_devices) + (SELECT COUNT(*) FROM student_device_challenges) + (SELECT COUNT(*) FROM student_device_events) + (SELECT COUNT(*) FROM student_device_sessions)'),
            'students' => $count("SELECT COUNT(*) FROM users WHERE role = 'student'"),
            'assessment_banks' => $count('SELECT COUNT(*) FROM assessment_banks'),
            'problem_banks' => $count('SELECT COUNT(*) FROM coding_problems'),
            'teaching_subjects' => $count('SELECT COUNT(*) FROM faculty_subjects'),
            'faculty_accounts' => $count("SELECT COUNT(*) FROM users WHERE role = 'faculty'"),
            'subjects' => $count('SELECT COUNT(*) FROM subjects'),
            'programs' => $count('SELECT COUNT(*) FROM programs'),
            'colleges' => $count('SELECT COUNT(*) FROM colleges'),
            'campuses' => $count('SELECT COUNT(*) FROM campuses'),
            'authentication_history' => $count('SELECT (SELECT COUNT(*) FROM login_attempts) + (SELECT COUNT(*) FROM password_resets)'),
        ];
    }
}
