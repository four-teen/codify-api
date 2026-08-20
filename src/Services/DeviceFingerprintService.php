<?php
declare(strict_types=1);

namespace Codify\Services;

use Codify\Core\HttpException;

final class DeviceFingerprintService
{
    private const SIGNAL_KEYS = [
        'version', 'browser_family', 'browser_major', 'os_family', 'device_type',
        'platform', 'timezone', 'languages', 'screen_bucket', 'pixel_ratio_bucket',
        'color_depth', 'hardware_concurrency_bucket', 'device_memory_bucket',
        'max_touch_points', 'storage_available',
    ];

    /** @var string */
    private $secret;
    /** @var string */
    private $configurationSource = 'unavailable';

    public function __construct(?string $secret = null, ?string $managedKeyPath = null)
    {
        if ($secret !== null) {
            $this->secret = trim($secret);
            $this->configurationSource = $this->usableSecret($this->secret) ? 'provided' : 'unavailable';
            return;
        }

        $environmentSecret = trim((string) env('DEVICE_FINGERPRINT_KEY', ''));
        if ($this->usableSecret($environmentSecret)) {
            $this->secret = $environmentSecret;
            $this->configurationSource = 'environment';
            return;
        }

        $defaultPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'device-fingerprint.key';
        $path = trim((string) ($managedKeyPath ?? env('DEVICE_FINGERPRINT_KEY_FILE', $defaultPath)));
        $this->secret = $this->managedSecret($path);
        if ($this->usableSecret($this->secret)) $this->configurationSource = 'managed_file';
    }

    public function configured(): bool { return $this->usableSecret($this->secret); }

    public function configurationSource(): string { return $this->configurationSource; }

    public function build(int $studentId, array $signals): array
    {
        $this->assertConfigured();
        $normalized = $this->normalize($signals);
        $components = [];
        foreach ($normalized as $key => $value) $components[$key] = $this->hmac('student:' . $studentId . '|component:' . $key . '|' . $this->encode($value));
        return [
            'fingerprint_hash' => $this->hmac('student:' . $studentId . '|fingerprint:v1|' . $this->encode($normalized)),
            'component_hashes' => $components,
            'device_label' => $this->deviceLabel($normalized),
            'browser_label' => trim($normalized['browser_family'] . ($normalized['browser_major'] !== '' ? ' ' . $normalized['browser_major'] : '')),
            'os_label' => $normalized['os_family'],
            'device_type' => $normalized['device_type'],
        ];
    }

    public function changedComponents(string $storedJson, array $current): array
    {
        $stored = json_decode($storedJson, true);
        if (!is_array($stored)) return ['device characteristics'];
        $labels = [
            'browser_family' => 'browser', 'browser_major' => 'browser version', 'os_family' => 'operating system',
            'device_type' => 'device type', 'platform' => 'platform', 'timezone' => 'timezone', 'languages' => 'language',
            'screen_bucket' => 'screen', 'pixel_ratio_bucket' => 'display scale', 'color_depth' => 'display color',
            'hardware_concurrency_bucket' => 'processor profile', 'device_memory_bucket' => 'memory profile',
            'max_touch_points' => 'touch capability', 'storage_available' => 'browser storage',
        ];
        $changed = [];
        foreach ($labels as $key => $label) {
            if (!isset($stored[$key]) || !isset($current[$key]) || !hash_equals((string) $stored[$key], (string) $current[$key])) $changed[] = $label;
        }
        return array_values(array_unique($changed));
    }

    public function networkHash(int $studentId, string $ip): ?string
    {
        if (!$this->configured() || trim($ip) === '' || $ip === 'unknown') return null;
        $prefix = $this->networkPrefix($ip);
        return $prefix === null ? null : $this->hmac('student:' . $studentId . '|network|' . $prefix);
    }

    public function noticeHash(string $policyVersion, string $notice): string
    {
        return hash('sha256', trim($policyVersion) . chr(10) . trim($notice));
    }

    private function normalize(array $signals): array
    {
        $normalized = [];
        foreach (self::SIGNAL_KEYS as $key) {
            $value = $signals[$key] ?? '';
            if ($key === 'languages') {
                $items = is_array($value) ? array_slice($value, 0, 5) : [$value];
                $items = array_values(array_filter(array_map(function ($item): string { return $this->text($item, 20); }, $items)));
                $normalized[$key] = implode(',', $items);
            } elseif ($key === 'storage_available') {
                $normalized[$key] = filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 'yes' : 'no';
            } else {
                $normalized[$key] = $this->text($value, $key === 'version' ? 8 : 80);
            }
        }
        if ($normalized['version'] !== '1') throw new HttpException(422, 'The device signal version is unsupported.', ['signals' => ['Refresh Codify and try again.']]);
        if (!in_array($normalized['device_type'], ['desktop', 'tablet', 'mobile', 'unknown'], true)) $normalized['device_type'] = 'unknown';
        foreach (['browser_family', 'os_family', 'timezone', 'screen_bucket'] as $required) {
            if ($normalized[$required] === '') throw new HttpException(422, 'The device report is incomplete.', ['signals' => ['Allow standard browser information and try again.']]);
        }
        ksort($normalized);
        return $normalized;
    }

    private function deviceLabel(array $signals): string
    {
        $type = ucfirst($signals['device_type'] === 'unknown' ? 'device' : $signals['device_type']);
        $browser = $signals['browser_family'] === '' ? 'Browser' : $signals['browser_family'];
        $os = $signals['os_family'] === '' ? 'Unknown OS' : $signals['os_family'];
        return substr($type . ' - ' . $browser . ' on ' . $os, 0, 120);
    }

    private function text($value, int $max): string
    {
        if (is_array($value) || is_object($value)) return '';
        $text = trim((string) $value);
        $text = (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text);
        return function_exists('mb_substr') ? mb_substr($text, 0, $max) : substr($text, 0, $max);
    }

    private function networkPrefix(string $ip): ?string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return implode('.', array_slice(explode('.', $ip), 0, 3)) . '.0/24';
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $packed = inet_pton($ip);
            return $packed === false ? null : bin2hex(substr($packed, 0, 6)) . '/48';
        }
        return null;
    }

    private function encode($value): string { return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); }
    private function hmac(string $value): string { return hash_hmac('sha256', $value, $this->secret); }
    private function usableSecret(string $secret): bool
    {
        if (strlen($secret) < 32) return false;
        return preg_match('/(?:replace[-_ ]with|change[-_ ]me|example[-_ ]key|your[-_ ]secret)/i', $secret) !== 1;
    }

    private function managedSecret(string $path): string
    {
        if ($path === '') return '';
        $directory = dirname($path);
        if (!is_dir($directory) || !is_writable($directory)) {
            error_log('Codify device fingerprint key storage is not writable: ' . $directory);
            return '';
        }

        $handle = @fopen($path, 'c+b');
        if ($handle === false) {
            error_log('Codify could not open its managed device fingerprint key file.');
            return '';
        }

        try {
            if (!flock($handle, LOCK_EX)) return '';
            rewind($handle);
            $stored = trim((string) stream_get_contents($handle));
            if ($this->usableSecret($stored)) return $stored;

            $generated = bin2hex(random_bytes(32));
            if (!ftruncate($handle, 0) || !rewind($handle) || fwrite($handle, $generated . PHP_EOL) === false || !fflush($handle)) return '';
            @chmod($path, 0600);
            return $generated;
        } catch (\Throwable $exception) {
            error_log('Codify could not create its managed device fingerprint key: ' . $exception->getMessage());
            return '';
        } finally {
            @flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function assertConfigured(): void
    {
        if (!$this->configured()) throw new HttpException(503, 'Device consistency could not initialize its server key. Ensure the storage directory is writable or set DEVICE_FINGERPRINT_KEY.', [], 'DEVICE_CONSISTENCY_UNAVAILABLE');
    }
}
