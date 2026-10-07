<?php
declare(strict_types=1);
namespace Codify\Services;

use Codify\Core\HttpException;

final class PythonRubricScorer
{
    public function analyze(string $code): array
    {
        if (!function_exists('proc_open')) throw new HttpException(503, 'Python code checks are unavailable on this server. Faculty can still enter final scores.');
        $binary = (string) env('PYTHON_ANALYZER_BINARY', 'python');
        $script = dirname(__DIR__, 2) . '/scripts/analyze-python.py';
        $command = escapeshellarg($binary) . ' -I -S -B ' . escapeshellarg($script);
        $process = @proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($process)) throw new HttpException(503, 'Configure PYTHON_ANALYZER_BINARY on the API server to enable initial code checks.');
        try {
            $input = json_encode(['code' => $code]);
            if ($input === false) throw new HttpException(422, 'Code must contain valid UTF-8 text.');
            fwrite($pipes[0], $input); fclose($pipes[0]);
            stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false);
            $output = ''; $deadline = microtime(true) + 5;
            do {
                $output .= stream_get_contents($pipes[1]);
                stream_get_contents($pipes[2]);
                $running = proc_get_status($process)['running'];
                if (!$running) break;
                if (microtime(true) > $deadline || strlen($output) > 100000) { proc_terminate($process); throw new HttpException(503, 'Python analysis timed out. Faculty can enter scores manually.'); }
                usleep(10000);
            } while (true);
            $output .= stream_get_contents($pipes[1]);
            $result = json_decode($output, true);
            if (!is_array($result) || !isset($result['syntax_valid'])) throw new HttpException(503, 'Python analysis is unavailable. Check PYTHON_ANALYZER_BINARY on the API server.');
            return $result;
        } finally {
            foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
            proc_close($process);
        }
    }

    public function score(string $code, array $rubric): array
    {
        $analysis = $this->analyze($code);
        $rows = []; $earned = 0; $checked = 0; $pending = 0;
        foreach ($rubric['criteria'] as $criterion) {
            $max = (float) $criterion['max_points']; $score = null;
            $evidence = 'Logic, correctness, and fulfillment of written requirements need faculty review.';
            if ($criterion['check'] === 'syntax') {
                $score = $analysis['syntax_valid'] ? $max : 0;
                $evidence = $analysis['syntax_valid'] ? 'Python syntax parsed successfully. This does not prove the output is correct.' : 'Syntax error on line ' . ($analysis['syntax_error']['line'] ?? '?') . ': ' . $analysis['syntax_error']['message'];
            } elseif ($criterion['check'] === 'features' && $analysis['syntax_valid']) {
                $required = $criterion['required_features']; $present = array_values(array_intersect($required, $analysis['features']));
                $score = round($max * count($present) / count($required), 2);
                $missing = array_values(array_diff($required, $present));
                $evidence = 'Found: ' . (implode(', ', $present) ?: 'none') . '. Missing: ' . (implode(', ', $missing) ?: 'none') . '. Presence alone does not prove correct use or execution.';
            } elseif ($criterion['check'] === 'formatting') {
                $score = $analysis['formatting'] === [] ? $max : 0;
                $evidence = $analysis['formatting'] === [] ? 'No tab indentation or lines over 88 characters found. Readability still needs faculty judgment.' : implode(' ', $analysis['formatting']);
            } elseif ($criterion['check'] === 'features') {
                $evidence = 'Construct checks need valid syntax; faculty should review this criterion.';
            }
            if ($score === null) $pending += $max; else { $checked += $max; $earned += $score; }
            $rows[] = array_merge($criterion, ['score' => $score, 'status' => $score === null ? 'needs_review' : ($score >= $max ? 'pass' : ($score > 0 ? 'partial' : 'fail')), 'evidence' => $evidence]);
        }
        return ['earned' => round($earned, 2), 'checked_points' => round($checked, 2), 'pending_points' => round($pending, 2), 'max_points' => round($checked + $pending, 2), 'criteria' => $rows, 'generated_at' => date(DATE_ATOM), 'method' => 'static_python_checks', 'syntax_valid' => $analysis['syntax_valid']];
    }
}
