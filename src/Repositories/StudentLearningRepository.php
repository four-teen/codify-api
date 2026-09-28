<?php
declare(strict_types=1);

namespace Codify\Repositories;

use Codify\Core\HttpException;
use PDO;
use Throwable;

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
            'recent_problems' => array_slice($problems, 0, 5),
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
        $assessments = $this->assessments($studentId, $offeringId, $academicYear, $academicTerm);
        $quizzes = 0; $exams = 0;
        foreach ($assessments as $assessment) {
            if ($assessment['bank_type'] === 'exam') $exams++;
            else $quizzes++;
        }
        return [
            'subject' => $subject,
            'problems' => $problems,
            'assessments' => $assessments,
            'metrics' => ['active_problems' => count($problems), 'sample_cases' => $sampleCases, 'quizzes' => $quizzes, 'exams' => $exams, 'assessments' => count($assessments)],
        ];
    }

    public function assessments(int $studentId, int $offeringId, string $academicYear, string $academicTerm): array
    {
        $statement = $this->db->prepare("SELECT bank.id, bank.code, bank.title, bank.bank_type, bank.description, bank.instructions, bank.updated_at,
            (SELECT COUNT(*) FROM assessment_bank_questions question WHERE question.assessment_bank_id = bank.id) AS questions_count,
            (SELECT COALESCE(SUM(question.points), 0) FROM assessment_bank_questions question WHERE question.assessment_bank_id = bank.id) AS total_points,
            (SELECT COUNT(*) FROM student_assessment_attempts attempt WHERE attempt.assessment_bank_id = bank.id AND attempt.faculty_subject_id = fs.id AND attempt.student_id = :attempt_student) AS attempts_count,
            (SELECT attempt.auto_score FROM student_assessment_attempts attempt WHERE attempt.assessment_bank_id = bank.id AND attempt.faculty_subject_id = fs.id AND attempt.student_id = :score_student ORDER BY attempt.id DESC LIMIT 1) AS latest_score,
            (SELECT attempt.total_points FROM student_assessment_attempts attempt WHERE attempt.assessment_bank_id = bank.id AND attempt.faculty_subject_id = fs.id AND attempt.student_id = :total_student ORDER BY attempt.id DESC LIMIT 1) AS latest_total_points,
            (SELECT attempt.grading_status FROM student_assessment_attempts attempt WHERE attempt.assessment_bank_id = bank.id AND attempt.faculty_subject_id = fs.id AND attempt.student_id = :status_student ORDER BY attempt.id DESC LIMIT 1) AS latest_grading_status,
            (SELECT attempt.submitted_at FROM student_assessment_attempts attempt WHERE attempt.assessment_bank_id = bank.id AND attempt.faculty_subject_id = fs.id AND attempt.student_id = :submitted_student ORDER BY attempt.id DESC LIMIT 1) AS latest_submitted_at,
            (SELECT AVG(attempt.auto_score) FROM student_assessment_attempts attempt WHERE attempt.assessment_bank_id = bank.id AND attempt.faculty_subject_id = fs.id AND attempt.student_id = :average_score_student) AS average_score,
            (SELECT AVG(attempt.total_points) FROM student_assessment_attempts attempt WHERE attempt.assessment_bank_id = bank.id AND attempt.faculty_subject_id = fs.id AND attempt.student_id = :average_points_student) AS average_total_points,
            (SELECT AVG(CASE WHEN attempt.total_points > 0 THEN (attempt.auto_score / attempt.total_points) * 100 ELSE 0 END) FROM student_assessment_attempts attempt WHERE attempt.assessment_bank_id = bank.id AND attempt.faculty_subject_id = fs.id AND attempt.student_id = :average_percent_student) AS final_score_percent,
            COALESCE(retake.additional_attempts, 0) AS additional_attempts
            FROM assessment_banks bank
            INNER JOIN assessment_bank_subjects bank_subject ON bank_subject.assessment_bank_id = bank.id
            INNER JOIN faculty_subjects fs ON fs.id = bank_subject.faculty_subject_id AND fs.faculty_id = bank.faculty_id
            INNER JOIN faculty_subject_students enrollment ON enrollment.faculty_subject_id = fs.id
            LEFT JOIN student_assessment_retake_permissions retake ON retake.assessment_bank_id = bank.id AND retake.faculty_subject_id = fs.id AND retake.student_id = enrollment.student_id
            WHERE enrollment.student_id = :student AND fs.id = :offering
                AND bank.is_active = 1 AND fs.is_active = 1
                AND fs.academic_year = :academic_year AND fs.academic_term = :academic_term
            ORDER BY FIELD(bank.bank_type, 'quiz', 'exam'), bank.updated_at DESC, bank.code");
        $statement->execute([
            'student' => $studentId, 'offering' => $offeringId, 'academic_year' => $academicYear, 'academic_term' => $academicTerm,
            'attempt_student' => $studentId, 'score_student' => $studentId, 'total_student' => $studentId,
            'status_student' => $studentId, 'submitted_student' => $studentId,
            'average_score_student' => $studentId, 'average_points_student' => $studentId, 'average_percent_student' => $studentId,
        ]);
        return array_map(static function (array $row): array {
            $attempts = (int) $row['attempts_count'];
            $additionalAttempts = (int) $row['additional_attempts'];
            return [
                'id' => (int) $row['id'], 'code' => $row['code'], 'title' => $row['title'], 'bank_type' => $row['bank_type'],
                'description' => $row['description'], 'instructions' => $row['instructions'],
                'questions_count' => (int) $row['questions_count'], 'total_points' => (int) $row['total_points'], 'updated_at' => $row['updated_at'],
                'attempts_count' => $attempts,
                'can_attempt' => $attempts < 1 + $additionalAttempts,
                'retakes_remaining' => max(0, $additionalAttempts - max(0, $attempts - 1)),
                'latest_score' => $row['latest_score'] === null ? null : (int) $row['latest_score'],
                'latest_total_points' => $row['latest_total_points'] === null ? null : (int) $row['latest_total_points'],
                'average_score' => $row['average_score'] === null ? null : round((float) $row['average_score'], 2),
                'average_total_points' => $row['average_total_points'] === null ? null : round((float) $row['average_total_points'], 2),
                'final_score_percent' => $row['final_score_percent'] === null ? null : round((float) $row['final_score_percent'], 1),
                'latest_grading_status' => $row['latest_grading_status'], 'latest_submitted_at' => $row['latest_submitted_at'],
            ];
        }, $statement->fetchAll());
    }

    public function assessment(int $studentId, int $offeringId, int $assessmentId, string $academicYear, string $academicTerm): array
    {
        $assessment = $this->assessmentSummary($studentId, $offeringId, $assessmentId, $academicYear, $academicTerm);
        $this->assertAssessmentAttemptAvailable($assessment);
        $assessment['questions'] = array_map(static function (array $question): array {
            return [
                'id' => (int) $question['id'], 'position' => (int) $question['position'], 'question_type' => $question['question_type'],
                'question_text' => $question['question_text'], 'options' => self::decodedJson($question['options_json']),
                'points' => (int) $question['points'], 'is_required' => (bool) $question['is_required'],
            ];
        }, $this->assessmentQuestionRows($assessmentId));
        return $assessment;
    }

    public function submitAssessment(int $studentId, int $offeringId, int $assessmentId, string $academicYear, string $academicTerm, array $answers): array
    {
        $assessment = $this->assessmentSummary($studentId, $offeringId, $assessmentId, $academicYear, $academicTerm);
        $this->assertAssessmentAttemptAvailable($assessment);
        if (count($answers) > 100) throw new HttpException(422, 'The assessment response contains too many answers.');
        $answerMap = [];
        foreach ($answers as $answer) {
            if (!is_array($answer)) throw new HttpException(422, 'One or more assessment answers are invalid.');
            $questionId = filter_var($answer['question_id'] ?? null, FILTER_VALIDATE_INT);
            if ($questionId === false || $questionId < 1) throw new HttpException(422, 'One or more assessment answers are missing a question.');
            $answerMap[(int) $questionId] = $answer['answer'] ?? null;
        }

        $questions = $this->assessmentQuestionRows($assessmentId);
        $questionIds = array_map(static function (array $question): int { return (int) $question['id']; }, $questions);
        foreach (array_keys($answerMap) as $questionId) {
            if (!in_array($questionId, $questionIds, true)) throw new HttpException(422, 'An answer does not belong to this assessment.');
        }

        $storedAnswers = []; $autoScore = 0; $totalPoints = 0; $pendingPoints = 0; $needsReview = false;
        foreach ($questions as $question) {
            $questionId = (int) $question['id']; $type = $question['question_type']; $points = (int) $question['points'];
            $options = self::decodedJson($question['options_json']); $correct = self::decodedJson($question['correct_answers_json']);
            $accepted = self::decodedJson($question['accepted_answers_json']); $raw = $answerMap[$questionId] ?? null;
            [$response, $answered] = $this->normalizedAssessmentAnswer($type, $raw, count($options));
            if ((bool) $question['is_required'] && !$answered) throw new HttpException(422, 'Answer every required question before submitting.');
            $storedAnswers[(string) $questionId] = $response; $totalPoints += $points;
            if (!$answered) continue;

            $manual = $type === 'paragraph' || ($type === 'short_answer' && $accepted === []);
            if ($manual) { $needsReview = true; $pendingPoints += $points; continue; }
            $isCorrect = false;
            if (in_array($type, ['multiple_choice', 'dropdown'], true)) {
                $isCorrect = count($correct) === 1 && (int) $correct[0] === $response;
            } elseif ($type === 'checkboxes') {
                $expected = array_values(array_unique(array_map('intval', $correct))); sort($expected);
                $isCorrect = $expected === $response;
            } elseif ($type === 'true_false') {
                $isCorrect = count($correct) === 1 && (string) $correct[0] === $response;
            } elseif ($type === 'short_answer') {
                $candidate = trim((string) $response);
                foreach ($accepted as $acceptedAnswer) {
                    $expected = trim((string) $acceptedAnswer);
                    if (!(bool) $question['case_sensitive']) {
                        $candidateValue = function_exists('mb_strtolower') ? mb_strtolower($candidate) : strtolower($candidate);
                        $expected = function_exists('mb_strtolower') ? mb_strtolower($expected) : strtolower($expected);
                    } else $candidateValue = $candidate;
                    if ($candidateValue === $expected) { $isCorrect = true; break; }
                }
            }
            if ($isCorrect) $autoScore += $points;
        }

        $json = json_encode($storedAnswers, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) throw new HttpException(422, 'The assessment answers could not be saved.');
        return $this->transaction(function () use ($studentId, $offeringId, $assessmentId, $assessment, $json, $autoScore, $totalPoints, $pendingPoints, $needsReview): array {
            $enrollment = $this->db->prepare('SELECT student_id FROM faculty_subject_students WHERE faculty_subject_id = :offering AND student_id = :student FOR UPDATE');
            $enrollment->execute(['offering' => $offeringId, 'student' => $studentId]);
            if (!$enrollment->fetchColumn()) throw new HttpException(404, 'This assessment is not active in your enrolled subject.');

            $count = $this->db->prepare('SELECT COUNT(*) FROM student_assessment_attempts WHERE assessment_bank_id = :assessment AND faculty_subject_id = :offering AND student_id = :student');
            $count->execute(['assessment' => $assessmentId, 'offering' => $offeringId, 'student' => $studentId]);
            $attemptsCount = (int) $count->fetchColumn();
            $permission = $this->db->prepare('SELECT additional_attempts FROM student_assessment_retake_permissions WHERE assessment_bank_id = :assessment AND faculty_subject_id = :offering AND student_id = :student FOR UPDATE');
            $permission->execute(['assessment' => $assessmentId, 'offering' => $offeringId, 'student' => $studentId]);
            $additionalAttempts = (int) ($permission->fetchColumn() ?: 0);
            if ($attemptsCount >= 1 + $additionalAttempts) {
                throw new HttpException(409, 'You have already submitted this assessment. Your faculty instructor must allow a retake before you can take it again.');
            }
            $statement = $this->db->prepare("INSERT INTO student_assessment_attempts
                (assessment_bank_id, faculty_subject_id, student_id, answers_json, grading_status, auto_score, total_points, pending_review_points, submitted_at, created_at)
                VALUES (:assessment, :offering, :student, :answers, :grading_status, :auto_score, :total_points, :pending_points, NOW(), NOW())");
            $statement->execute([
                'assessment' => $assessmentId, 'offering' => $offeringId, 'student' => $studentId, 'answers' => $json,
                'grading_status' => $needsReview ? 'pending_review' : 'graded', 'auto_score' => $autoScore,
                'total_points' => $totalPoints, 'pending_points' => $pendingPoints,
            ]);
            $attemptId = (int) $this->db->lastInsertId();
            $average = $this->db->prepare('SELECT AVG(auto_score) AS average_score, AVG(total_points) AS average_total_points, AVG(CASE WHEN total_points > 0 THEN (auto_score / total_points) * 100 ELSE 0 END) AS final_score_percent FROM student_assessment_attempts WHERE assessment_bank_id = :assessment AND faculty_subject_id = :offering AND student_id = :student');
            $average->execute(['assessment' => $assessmentId, 'offering' => $offeringId, 'student' => $studentId]);
            $averageRow = $average->fetch() ?: [];
            return [
                'attempt_id' => $attemptId, 'grading_status' => $needsReview ? 'pending_review' : 'graded',
                'auto_score' => $autoScore, 'total_points' => $totalPoints, 'pending_review_points' => $pendingPoints,
                'attempts_count' => $attemptsCount + 1, 'submitted_at' => date('Y-m-d H:i:s'),
                'average_score' => round((float) ($averageRow['average_score'] ?? $autoScore), 2),
                'average_total_points' => round((float) ($averageRow['average_total_points'] ?? $totalPoints), 2),
                'final_score_percent' => round((float) ($averageRow['final_score_percent'] ?? 0), 1),
            ];
        });
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
                WHERE cps.faculty_subject_id = fs.id) AS problems_count,
            (SELECT COUNT(DISTINCT bank_subject.assessment_bank_id)
                FROM assessment_bank_subjects bank_subject
                INNER JOIN assessment_banks bank ON bank.id = bank_subject.assessment_bank_id AND bank.is_active = 1
                WHERE bank_subject.faculty_subject_id = fs.id) AS assessments_count
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

    public function problem(int $studentId, int $problemId, string $academicYear, string $academicTerm, int $offeringId = 0): array
    {
        $offeringScope = $offeringId > 0 ? ' AND fs.id = :offering' : '';
        $statement = $this->db->prepare("SELECT cp.id, cp.code, cp.title, cp.language, cp.difficulty,
            cp.problem_statement, cp.input_format, cp.output_format, cp.constraints_text, cp.starter_code,
            cp.tags, cp.time_limit_ms, cp.memory_limit_mb, cp.created_at, cp.updated_at
            FROM coding_problems cp
            WHERE cp.id = :problem AND cp.is_active = 1 AND EXISTS (
                SELECT 1 FROM coding_problem_subjects cps
                INNER JOIN faculty_subjects fs ON fs.id = cps.faculty_subject_id
                INNER JOIN faculty_subject_students fss ON fss.faculty_subject_id = fs.id
                WHERE cps.problem_id = cp.id AND fss.student_id = :student AND fs.is_active = 1
                  AND fs.academic_year = :academic_year AND fs.academic_term = :academic_term {$offeringScope}
            ) LIMIT 1");
        $parameters = ['problem' => $problemId, 'student' => $studentId, 'academic_year' => $academicYear, 'academic_term' => $academicTerm];
        if ($offeringId > 0) $parameters['offering'] = $offeringId;
        $statement->execute($parameters);
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
        if ($offeringId > 0) $problem['subjects'] = array_values(array_filter($problem['subjects'], static function (array $subject) use ($offeringId): bool { return $subject['id'] === $offeringId; }));
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

    private function assessmentSummary(int $studentId, int $offeringId, int $assessmentId, string $academicYear, string $academicTerm): array
    {
        foreach ($this->assessments($studentId, $offeringId, $academicYear, $academicTerm) as $assessment) {
            if ((int) $assessment['id'] === $assessmentId) return $assessment;
        }
        throw new HttpException(404, 'This assessment is not active in your enrolled subject.');
    }

    private function assertAssessmentAttemptAvailable(array $assessment): void
    {
        if (!$assessment['can_attempt']) {
            throw new HttpException(409, 'You have already submitted this assessment. Your faculty instructor must allow a retake before you can take it again.');
        }
    }

    private function assessmentQuestionRows(int $assessmentId): array
    {
        $statement = $this->db->prepare('SELECT id, position, question_type, question_text, options_json, correct_answers_json, accepted_answers_json, case_sensitive, points, is_required FROM assessment_bank_questions WHERE assessment_bank_id = :assessment ORDER BY position, id');
        $statement->execute(['assessment' => $assessmentId]);
        return $statement->fetchAll();
    }

    private function normalizedAssessmentAnswer(string $type, $raw, int $optionCount): array
    {
        if (in_array($type, ['multiple_choice', 'dropdown'], true)) {
            if ($raw === null || $raw === '') return [null, false];
            $index = filter_var($raw, FILTER_VALIDATE_INT);
            if ($index === false || $index < 0 || $index >= $optionCount) throw new HttpException(422, 'A selected answer is outside the available choices.');
            return [(int) $index, true];
        }
        if ($type === 'checkboxes') {
            if ($raw === null || $raw === []) return [[], false];
            if (!is_array($raw)) throw new HttpException(422, 'A checkbox response is invalid.');
            $indexes = [];
            foreach ($raw as $value) {
                $index = filter_var($value, FILTER_VALIDATE_INT);
                if ($index === false || $index < 0 || $index >= $optionCount) throw new HttpException(422, 'A selected checkbox answer is outside the available choices.');
                $indexes[(int) $index] = (int) $index;
            }
            $indexes = array_values($indexes); sort($indexes);
            return [$indexes, $indexes !== []];
        }
        if ($type === 'true_false') {
            if ($raw === null || $raw === '') return [null, false];
            $value = (string) $raw;
            if (!in_array($value, ['true', 'false'], true)) throw new HttpException(422, 'A true-or-false response is invalid.');
            return [$value, true];
        }
        if (in_array($type, ['short_answer', 'paragraph'], true)) {
            $value = trim((string) ($raw ?? ''));
            $maximum = $type === 'short_answer' ? 500 : 30000;
            $length = function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
            if ($length > $maximum) throw new HttpException(422, 'A written assessment response is too long.');
            return [$value, $value !== ''];
        }
        throw new HttpException(422, 'An assessment question has an unsupported response type.');
    }

    private static function decodedJson(?string $value): array
    {
        if ($value === null || $value === '') return [];
        $decoded = json_decode($value, true);
        return is_array($decoded) ? array_values($decoded) : [];
    }

    private function transaction(callable $callback)
    {
        $owns = !$this->db->inTransaction();
        if ($owns) $this->db->beginTransaction();
        try { $result = $callback(); if ($owns) $this->db->commit(); return $result; }
        catch (Throwable $exception) { if ($owns && $this->db->inTransaction()) $this->db->rollBack(); throw $exception; }
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
            'assessments_count' => (int) $row['assessments_count'],
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
