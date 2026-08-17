<?php
declare(strict_types=1);

namespace Codify\Repositories;

use Codify\Core\HttpException;
use PDO;

final class StudentLearningRepository
{
    /** @var PDO */
    private $db;

    public function __construct(PDO $db) { $this->db = $db; }

    public function overview(int $studentId, string $academicYear, string $academicTerm): array
    {
        $subjects = $this->subjects($studentId, $academicYear, $academicTerm);
        $problems = $this->problems($studentId, $academicYear, $academicTerm, [
            'search' => '', 'difficulty' => '', 'offering_id' => 0,
        ]);
        $sampleCases = 0;
        foreach ($problems as $problem) $sampleCases += (int) $problem['sample_cases_count'];

        return [
            'profile' => $this->profile($studentId),
            'academic_year' => $academicYear,
            'academic_term' => $academicTerm,
            'metrics' => [
                'subjects' => count($subjects),
                'active_problems' => count($problems),
                'sample_cases' => $sampleCases,
            ],
            'subjects' => $subjects,
        ];
    }

    public function subjectDashboard(int $studentId, int $offeringId, string $academicYear, string $academicTerm): array
    {
        $subject = null;
        foreach ($this->subjects($studentId, $academicYear, $academicTerm) as $candidate) {
            if ((int) $candidate['id'] === $offeringId) { $subject = $candidate; break; }
        }
        if ($subject === null) throw new HttpException(404, 'Subject not found in your current workspace.');

        $problems = $this->problems($studentId, $academicYear, $academicTerm, [
            'search' => '', 'difficulty' => '', 'offering_id' => $offeringId,
        ]);
        $sampleCases = 0;
        foreach ($problems as $problem) $sampleCases += (int) $problem['sample_cases_count'];
        return [
            'subject' => $subject,
            'problems' => $problems,
            'metrics' => ['active_problems' => count($problems), 'sample_cases' => $sampleCases],
        ];
    }

    public function syllabus(int $studentId, int $offeringId, string $academicYear, string $academicTerm): array
    {
        $statement = $this->db->prepare('SELECT syllabus.faculty_subject_id, syllabus.original_name, syllabus.stored_name, syllabus.mime_type, syllabus.size_bytes, syllabus.uploaded_at, syllabus.updated_at FROM faculty_subject_syllabi syllabus INNER JOIN faculty_subjects fs ON fs.id = syllabus.faculty_subject_id INNER JOIN faculty_subject_students enrollment ON enrollment.faculty_subject_id = fs.id WHERE enrollment.student_id = :student AND fs.id = :offering AND fs.is_active = 1 AND fs.academic_year = :academic_year AND fs.academic_term = :academic_term LIMIT 1');
        $statement->execute(['student' => $studentId, 'offering' => $offeringId, 'academic_year' => $academicYear, 'academic_term' => $academicTerm]);
        $row = $statement->fetch(); if (!$row) throw new HttpException(404, 'No syllabus PDF is available for this enrolled subject.');
        $row['faculty_subject_id'] = (int) $row['faculty_subject_id']; $row['size_bytes'] = (int) $row['size_bytes']; return $row;
    }

    public function subjects(int $studentId, string $academicYear, string $academicTerm): array
    {
        $statement = $this->db->prepare("SELECT fs.id, fs.section, fs.class_schedule, fs.academic_year, fs.academic_term,
            s.id AS subject_id, s.code AS subject_code, s.name AS subject_name, s.units,
            p.id AS program_id, p.code AS program_code, p.name AS program_name,
            co.code AS college_code, co.name AS college_name,
            faculty.name AS instructor_name, faculty.email AS instructor_email,
            fss.source, fss.created_at AS enrolled_at,
            syllabus.original_name AS syllabus_original_name, syllabus.size_bytes AS syllabus_size_bytes, syllabus.uploaded_at AS syllabus_uploaded_at,
            (SELECT COUNT(DISTINCT cps.problem_id)
                FROM coding_problem_subjects cps
                INNER JOIN coding_problems cp ON cp.id = cps.problem_id AND cp.is_active = 1
                WHERE cps.faculty_subject_id = fs.id) AS problems_count
            FROM faculty_subject_students fss
            INNER JOIN faculty_subjects fs ON fs.id = fss.faculty_subject_id
            INNER JOIN subjects s ON s.id = fs.subject_id
            INNER JOIN programs p ON p.id = s.program_id
            INNER JOIN colleges co ON co.id = p.college_id
            INNER JOIN users faculty ON faculty.id = fs.faculty_id
            LEFT JOIN faculty_subject_syllabi syllabus ON syllabus.faculty_subject_id = fs.id
            WHERE fss.student_id = :student AND fs.is_active = 1
              AND fs.academic_year = :academic_year AND fs.academic_term = :academic_term
            ORDER BY p.code, s.code, fs.section");
        $statement->execute(['student' => $studentId, 'academic_year' => $academicYear, 'academic_term' => $academicTerm]);
        return array_map([$this, 'subjectPayload'], $statement->fetchAll());
    }

    public function problems(int $studentId, string $academicYear, string $academicTerm, array $filters): array
    {
        $where = [
            'fss.student_id = :student', 'cp.is_active = 1', 'fs.is_active = 1',
            'fs.academic_year = :academic_year', 'fs.academic_term = :academic_term',
        ];
        $parameters = ['student' => $studentId, 'academic_year' => $academicYear, 'academic_term' => $academicTerm];
        if ($filters['search'] !== '') {
            $where[] = '(cp.code LIKE :search_code OR cp.title LIKE :search_title OR cp.tags LIKE :search_tags)';
            $term = '%' . $filters['search'] . '%';
            $parameters['search_code'] = $term; $parameters['search_title'] = $term; $parameters['search_tags'] = $term;
        }
        if ($filters['difficulty'] !== '') {
            $where[] = 'cp.difficulty = :difficulty'; $parameters['difficulty'] = $filters['difficulty'];
        }
        if ($filters['offering_id'] > 0) {
            $where[] = 'fs.id = :offering'; $parameters['offering'] = $filters['offering_id'];
        }

        $sql = "SELECT cp.id, cp.code, cp.title, cp.language, cp.difficulty, cp.tags,
            cp.time_limit_ms, cp.memory_limit_mb, cp.updated_at,
            COUNT(DISTINCT CASE WHEN sample_case.is_sample = 1 THEN sample_case.id END) AS sample_cases_count,
            GROUP_CONCAT(DISTINCT CONCAT(s.code, IF(fs.section = '', '', CONCAT(' / ', fs.section))) ORDER BY s.code, fs.section SEPARATOR ', ') AS subject_labels
            FROM coding_problems cp
            INNER JOIN coding_problem_subjects cps ON cps.problem_id = cp.id
            INNER JOIN faculty_subjects fs ON fs.id = cps.faculty_subject_id
            INNER JOIN faculty_subject_students fss ON fss.faculty_subject_id = fs.id
            INNER JOIN subjects s ON s.id = fs.subject_id
            LEFT JOIN coding_problem_test_cases sample_case ON sample_case.problem_id = cp.id AND sample_case.is_sample = 1
            WHERE " . implode(' AND ', $where) . "
            GROUP BY cp.id, cp.code, cp.title, cp.language, cp.difficulty, cp.tags, cp.time_limit_ms, cp.memory_limit_mb, cp.updated_at
            ORDER BY cp.updated_at DESC, cp.code LIMIT 200";
        $statement = $this->db->prepare($sql); $statement->execute($parameters);
        return array_map([$this, 'problemListPayload'], $statement->fetchAll());
    }

    public function problem(int $studentId, int $problemId, string $academicYear, string $academicTerm): array
    {
        $statement = $this->db->prepare("SELECT cp.id, cp.code, cp.title, cp.language, cp.difficulty,
            cp.problem_statement, cp.input_format, cp.output_format, cp.constraints_text, cp.starter_code,
            cp.tags, cp.time_limit_ms, cp.memory_limit_mb, cp.created_at, cp.updated_at
            FROM coding_problems cp
            WHERE cp.id = :problem AND cp.is_active = 1 AND EXISTS (
                SELECT 1 FROM coding_problem_subjects cps
                INNER JOIN faculty_subjects fs ON fs.id = cps.faculty_subject_id
                INNER JOIN faculty_subject_students fss ON fss.faculty_subject_id = fs.id
                WHERE cps.problem_id = cp.id AND fss.student_id = :student AND fs.is_active = 1
                  AND fs.academic_year = :academic_year AND fs.academic_term = :academic_term
            ) LIMIT 1");
        $statement->execute(['problem' => $problemId, 'student' => $studentId, 'academic_year' => $academicYear, 'academic_term' => $academicTerm]);
        $row = $statement->fetch();
        if (!$row) throw new HttpException(404, 'Python problem not found in your enrolled subjects.');

        $problem = [
            'id' => (int) $row['id'], 'code' => $row['code'], 'title' => $row['title'],
            'language' => $row['language'], 'difficulty' => $row['difficulty'],
            'problem_statement' => $row['problem_statement'], 'input_format' => $row['input_format'],
            'output_format' => $row['output_format'], 'constraints_text' => $row['constraints_text'],
            'starter_code' => $row['starter_code'], 'tags' => $row['tags'],
            'time_limit_ms' => (int) $row['time_limit_ms'], 'memory_limit_mb' => (int) $row['memory_limit_mb'],
            'created_at' => $row['created_at'], 'updated_at' => $row['updated_at'],
        ];
        $problem['subjects'] = $this->problemSubjects($studentId, $problemId, $academicYear, $academicTerm);
        $problem['sample_cases'] = $this->sampleCases($problemId);
        return $problem;
    }

    private function profile(int $studentId): array
    {
        $statement = $this->db->prepare("SELECT u.id, u.first_name, u.last_name, u.name, u.username, u.email,
            sp.student_number, sp.gender, sp.mobile_number, sp.course_label, sp.enrollment_status,
            p.id AS program_id, p.code AS program_code, p.name AS program_name,
            co.code AS college_code, co.name AS college_name, c.code AS campus_code, c.name AS campus_name
            FROM users u
            LEFT JOIN student_profiles sp ON sp.user_id = u.id
            LEFT JOIN programs p ON p.id = sp.program_id
            LEFT JOIN colleges co ON co.id = p.college_id
            LEFT JOIN campuses c ON c.id = co.campus_id
            WHERE u.id = :student AND u.role = 'student' LIMIT 1");
        $statement->execute(['student' => $studentId]);
        $row = $statement->fetch();
        if (!$row) throw new HttpException(404, 'Student profile not found.');
        return [
            'id' => (int) $row['id'], 'first_name' => $row['first_name'], 'last_name' => $row['last_name'],
            'name' => $row['name'], 'username' => $row['username'], 'email' => $row['email'],
            'student_number' => $row['student_number'], 'gender' => $row['gender'],
            'mobile_number' => $row['mobile_number'], 'course_label' => $row['course_label'],
            'enrollment_status' => $row['enrollment_status'],
            'program_id' => $row['program_id'] === null ? null : (int) $row['program_id'],
            'program_code' => $row['program_code'], 'program_name' => $row['program_name'],
            'college_code' => $row['college_code'], 'college_name' => $row['college_name'],
            'campus_code' => $row['campus_code'], 'campus_name' => $row['campus_name'],
        ];
    }

    private function problemSubjects(int $studentId, int $problemId, string $academicYear, string $academicTerm): array
    {
        $statement = $this->db->prepare("SELECT fs.id, fs.section, s.code AS subject_code, s.name AS subject_name,
            p.code AS program_code, faculty.name AS instructor_name
            FROM coding_problem_subjects cps
            INNER JOIN faculty_subjects fs ON fs.id = cps.faculty_subject_id
            INNER JOIN faculty_subject_students fss ON fss.faculty_subject_id = fs.id
            INNER JOIN subjects s ON s.id = fs.subject_id
            INNER JOIN programs p ON p.id = s.program_id
            INNER JOIN users faculty ON faculty.id = fs.faculty_id
            WHERE cps.problem_id = :problem AND fss.student_id = :student AND fs.is_active = 1
              AND fs.academic_year = :academic_year AND fs.academic_term = :academic_term
            ORDER BY s.code, fs.section");
        $statement->execute(['problem' => $problemId, 'student' => $studentId, 'academic_year' => $academicYear, 'academic_term' => $academicTerm]);
        return array_map(static function (array $row): array {
            return ['id' => (int) $row['id'], 'section' => $row['section'], 'subject_code' => $row['subject_code'],
                'subject_name' => $row['subject_name'], 'program_code' => $row['program_code'], 'instructor_name' => $row['instructor_name']];
        }, $statement->fetchAll());
    }

    private function sampleCases(int $problemId): array
    {
        $statement = $this->db->prepare('SELECT id, position, input_data, expected_output FROM coding_problem_test_cases WHERE problem_id = :problem AND is_sample = 1 ORDER BY position, id');
        $statement->execute(['problem' => $problemId]);
        return array_map(static function (array $row): array {
            return ['id' => (int) $row['id'], 'position' => (int) $row['position'],
                'input_data' => $row['input_data'], 'expected_output' => $row['expected_output']];
        }, $statement->fetchAll());
    }

    private function subjectPayload(array $row): array
    {
        return [
            'id' => (int) $row['id'], 'subject_id' => (int) $row['subject_id'],
            'section' => $row['section'], 'class_schedule' => $row['class_schedule'],
            'academic_year' => $row['academic_year'], 'academic_term' => $row['academic_term'],
            'subject_code' => $row['subject_code'], 'subject_name' => $row['subject_name'], 'units' => (int) $row['units'],
            'program_id' => (int) $row['program_id'], 'program_code' => $row['program_code'], 'program_name' => $row['program_name'],
            'college_code' => $row['college_code'], 'college_name' => $row['college_name'],
            'instructor_name' => $row['instructor_name'], 'instructor_email' => $row['instructor_email'],
            'syllabus' => $row['syllabus_original_name'] === null ? null : ['original_name' => $row['syllabus_original_name'], 'size_bytes' => (int) $row['syllabus_size_bytes'], 'uploaded_at' => $row['syllabus_uploaded_at']],
            'source' => $row['source'], 'enrolled_at' => $row['enrolled_at'], 'problems_count' => (int) $row['problems_count'],
        ];
    }

    private function problemListPayload(array $row): array
    {
        return [
            'id' => (int) $row['id'], 'code' => $row['code'], 'title' => $row['title'],
            'language' => $row['language'], 'difficulty' => $row['difficulty'], 'tags' => $row['tags'],
            'time_limit_ms' => (int) $row['time_limit_ms'], 'memory_limit_mb' => (int) $row['memory_limit_mb'],
            'sample_cases_count' => (int) $row['sample_cases_count'], 'subject_labels' => $row['subject_labels'] ?: '',
            'updated_at' => $row['updated_at'],
        ];
    }
}
