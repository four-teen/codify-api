<?php
declare(strict_types=1);

namespace Codify\Services;

use Codify\Core\HttpException;

final class Judge0RunnerService
{
    /** @var array */
    private $config;
    public function __construct(array $config) { $this->config = $config; }

    public function execute(string $code, string $stdin, int $timeLimitMs, int $memoryLimitMb): array
    {
        if (!$this->config['enabled']) {
            throw new HttpException(503, 'Python execution is not enabled on this Codify installation.', [], 'RUNNER_DISABLED');
        }
        $this->assertConfiguration();
        $cpuSeconds = max(0.1, min(5.0, $timeLimitMs / 1000));
        $memoryKb = max(16000, min(256000, $memoryLimitMb * 1024));
        $response = $this->request('/submissions?base64_encoded=true&wait=true', [
            'source_code' => base64_encode($code),
            'language_id' => (int) $this->config['python_language_id'],
            'stdin' => base64_encode($stdin),
            'cpu_time_limit' => $cpuSeconds,
            'wall_time_limit' => min(10.0, max(3.0, $cpuSeconds + 2.0)),
            'memory_limit' => $memoryKb,
            'max_processes_and_or_threads' => 20,
            'max_file_size' => 1024,
            'enable_network' => false,
        ]);
        if ($response['status'] < 200 || $response['status'] >= 300) $this->throwRemoteError($response);
        $data = $response['data'];
        if (!is_array($data) || !isset($data['status']) || !is_array($data['status'])) {
            throw new HttpException(503, 'The Python runner returned an incomplete result.', [], 'RUNNER_INVALID_RESPONSE');
        }
        $truncated = false;
        $statusId = (int) ($data['status']['id'] ?? 0);
        return [
            'success' => $statusId === 3,
            'status_id' => $statusId,
            'status' => (string) ($data['status']['description'] ?? 'Unknown'),
            'stdout' => $this->decodedText($data['stdout'] ?? null, $truncated),
            'stderr' => $this->decodedText($data['stderr'] ?? null, $truncated),
            'compile_output' => $this->decodedText($data['compile_output'] ?? null, $truncated),
            'message' => $this->decodedText($data['message'] ?? null, $truncated),
            'time_seconds' => isset($data['time']) && $data['time'] !== null ? (float) $data['time'] : null,
            'memory_kb' => isset($data['memory']) && $data['memory'] !== null ? (int) $data['memory'] : null,
            'exit_code' => isset($data['exit_code']) && $data['exit_code'] !== null ? (int) $data['exit_code'] : null,
            'exit_signal' => isset($data['exit_signal']) && $data['exit_signal'] !== null ? (int) $data['exit_signal'] : null,
            'output_truncated' => $truncated,
        ];
    }

    private function request(string $path, array $payload): array
    {
        $handle = curl_init($this->config['api_url'] . $path);
        if ($handle === false) throw new HttpException(503, 'The Python runner could not be initialized.', [], 'RUNNER_UNAVAILABLE');
        $headers = ['Accept: application/json', 'Content-Type: application/json', 'User-Agent: Codify/1.0'];
        if ($this->config['api_key'] !== '') $headers[] = 'X-RapidAPI-Key: ' . $this->config['api_key'];
        if ($this->config['api_host'] !== '') $headers[] = 'X-RapidAPI-Host: ' . $this->config['api_host'];
        if ($this->config['auth_token'] !== '') $headers[] = 'X-Auth-Token: ' . $this->config['auth_token'];
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($json === false) throw new HttpException(422, 'The Python source could not be encoded for execution.');
        curl_setopt_array($handle, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $json, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => (int) $this->config['connect_timeout_seconds'],
            CURLOPT_TIMEOUT => (int) $this->config['request_timeout_seconds'],
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ]);
        $body = curl_exec($handle); $curlError = curl_error($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE); curl_close($handle);
        if ($body === false) {
            error_log('Judge0 request failed: ' . $curlError);
            throw new HttpException(503, 'The Python runner is temporarily unavailable.', [], 'RUNNER_UNAVAILABLE');
        }
        $data = json_decode((string) $body, true);
        return ['status' => $status, 'data' => is_array($data) ? $data : null];
    }

    private function throwRemoteError(array $response): void
    {
        $status = (int) $response['status']; $data = $response['data'];
        $detail = is_array($data) ? (string) ($data['error'] ?? $data['message'] ?? '') : '';
        if ($status === 401 || $status === 403) throw new HttpException(503, 'The Python runner credentials are not configured correctly.', [], 'RUNNER_AUTH_FAILED');
        if ($status === 429 || $status === 503) throw new HttpException(503, 'The Python runner is busy. Try again shortly.', [], 'RUNNER_BUSY');
        error_log('Judge0 returned HTTP ' . $status . ($detail !== '' ? ': ' . $detail : ''));
        throw new HttpException(503, 'The Python runner could not process this request.', [], 'RUNNER_ERROR');
    }

    private function decodedText($value, bool &$truncated): string
    {
        if ($value === null || $value === '') return '';
        $decoded = base64_decode((string) $value, true); $text = $decoded === false ? (string) $value : $decoded;
        if (strlen($text) <= 20000) return $text;
        $truncated = true; return substr($text, 0, 20000) . "\n[Output truncated by Codify]";
    }

    private function assertConfiguration(): void
    {
        $url = (string) $this->config['api_url']; $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = (string) parse_url($url, PHP_URL_HOST);
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new HttpException(503, 'Configure the URL of your self-hosted Judge0 runner before enabling Python execution.', [], 'RUNNER_NOT_CONFIGURED');
        }
    }
}
