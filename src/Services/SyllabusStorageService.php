<?php
declare(strict_types=1);

namespace Codify\Services;

use Codify\Core\HttpException;

final class SyllabusStorageService
{
    private $directory;
    private $maxBytes;

    public function __construct(array $config)
    {
        $this->directory = rtrim(str_replace('\\', '/', (string) ($config['storage_path'] ?? '')), '/');
        $this->maxBytes = (int) ($config['max_bytes'] ?? 10485760);
    }

    public function store(?array $file): array
    {
        if ($file === null) throw new HttpException(422, 'Choose a PDF syllabus to upload.', ['syllabus' => ['A PDF file is required.']]);
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) throw new HttpException(422, $this->uploadError($error), ['syllabus' => [$this->uploadError($error)]]);
        $temporary = (string) ($file['tmp_name'] ?? ''); $size = (int) ($file['size'] ?? 0);
        $original = $this->originalName((string) ($file['name'] ?? 'syllabus.pdf'));
        if (strtolower((string) pathinfo($original, PATHINFO_EXTENSION)) !== 'pdf') throw new HttpException(422, 'Only PDF syllabus files are allowed.', ['syllabus' => ['The syllabus must use the .pdf extension.']]);
        if ($size < 1) throw new HttpException(422, 'The uploaded PDF is empty.', ['syllabus' => ['Choose a non-empty PDF file.']]);
        if ($size > $this->maxBytes) throw new HttpException(422, 'The syllabus PDF may not exceed ' . $this->sizeLabel($this->maxBytes) . '.', ['syllabus' => ['Choose a smaller PDF file.']]);
        if ($temporary === '' || !is_uploaded_file($temporary)) throw new HttpException(422, 'The syllabus upload could not be verified.', ['syllabus' => ['Upload the PDF again.']]);
        if (class_exists('finfo')) {
            $detector = new \finfo(FILEINFO_MIME_TYPE); $mime = (string) $detector->file($temporary);
            if (!in_array($mime, ['application/pdf', 'application/x-pdf'], true)) throw new HttpException(422, 'Only genuine PDF syllabus files are allowed.', ['syllabus' => ['The selected file content is not a PDF.']]);
        }
        $handle = fopen($temporary, 'rb');
        if ($handle === false) throw new HttpException(422, 'The syllabus PDF could not be inspected.', ['syllabus' => ['Upload the PDF again.']]);
        $header = (string) fread($handle, 16); $tailLength = min(2048, $size);
        fseek($handle, -$tailLength, SEEK_END); $tail = (string) fread($handle, $tailLength); fclose($handle);
        if (preg_match('/\A%PDF-\d\.\d/', $header) !== 1 || preg_match('/%%EOF\s*\z/', $tail) !== 1) throw new HttpException(422, 'The selected file does not have a valid PDF structure.', ['syllabus' => ['Choose a valid PDF document.']]);
        $this->ensureDirectory(); $stored = bin2hex(random_bytes(24)) . '.pdf'; $destination = $this->directory . '/' . $stored;
        if (!move_uploaded_file($temporary, $destination)) throw new HttpException(503, 'The syllabus could not be saved. Try again.');
        @chmod($destination, 0640);
        return ['original_name' => $original, 'stored_name' => $stored, 'mime_type' => 'application/pdf', 'size_bytes' => $size];
    }

    public function delete(?string $storedName): void
    {
        if ($storedName === null || !$this->validStoredName($storedName)) return;
        $path = $this->directory . '/' . $storedName;
        if (is_file($path) && !@unlink($path)) error_log('Unable to delete stored syllabus: ' . $storedName);
    }

    public function stream(array $syllabus): void
    {
        $stored = (string) ($syllabus['stored_name'] ?? '');
        if (!$this->validStoredName($stored)) throw new HttpException(404, 'Syllabus PDF not found.');
        $path = $this->directory . '/' . $stored;
        if (!is_file($path) || !is_readable($path)) throw new HttpException(404, 'Syllabus PDF not found.');
        $size = (int) filesize($path); $start = 0; $end = max(0, $size - 1); $status = 200;
        $range = trim((string) ($_SERVER['HTTP_RANGE'] ?? ''));
        if ($range !== '' && preg_match('/^bytes=(\d*)-(\d*)$/', $range, $matches) === 1) {
            if ($matches[1] === '' && $matches[2] !== '') { $length = min($size, (int) $matches[2]); $start = max(0, $size - $length); }
            else { $start = (int) $matches[1]; if ($matches[2] !== '') $end = min($end, (int) $matches[2]); }
            if ($start > $end || $start >= $size) { http_response_code(416); header('Content-Range: bytes */' . $size); exit; }
            $status = 206;
        }
        $length = $end - $start + 1; $original = $this->downloadName((string) ($syllabus['original_name'] ?? 'syllabus.pdf'));
        http_response_code($status);
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $original . '"; filename*=UTF-8\'\'' . rawurlencode((string) ($syllabus['original_name'] ?? 'syllabus.pdf')));
        header('Content-Length: ' . $length); header('Accept-Ranges: bytes');
        if ($status === 206) header("Content-Range: bytes {$start}-{$end}/{$size}");
        header('Cache-Control: private, max-age=300'); header('X-Content-Type-Options: nosniff'); header('X-Frame-Options: DENY');
        $handle = fopen($path, 'rb'); if ($handle === false) throw new HttpException(503, 'The syllabus PDF could not be opened.');
        fseek($handle, $start); $remaining = $length;
        while ($remaining > 0 && !feof($handle)) { $chunk = fread($handle, min(8192, $remaining)); if ($chunk === false) break; echo $chunk; $remaining -= strlen($chunk); }
        fclose($handle); exit;
    }

    private function ensureDirectory(): void
    {
        if ($this->directory === '') throw new HttpException(503, 'The syllabus storage path is not configured.');
        if (!is_dir($this->directory) && !mkdir($this->directory, 0750, true) && !is_dir($this->directory)) throw new HttpException(503, 'The syllabus storage directory could not be created.');
        if (!is_writable($this->directory)) throw new HttpException(503, 'The syllabus storage directory is not writable.');
    }

    private function originalName(string $name): string
    {
        $name = trim(basename(str_replace('\\', '/', $name)));
        if ($name === '') $name = 'syllabus.pdf';
        $length = function_exists('mb_strlen') ? mb_strlen($name) : strlen($name);
        if ($length > 255) throw new HttpException(422, 'The syllabus filename may not exceed 255 characters.');
        return preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?: 'syllabus.pdf';
    }

    private function downloadName(string $name): string
    {
        $fallback = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name) ?: 'syllabus.pdf';
        return str_replace(['"', "\r", "\n"], '', $fallback);
    }

    private function validStoredName(string $name): bool { return preg_match('/^[a-f0-9]{48}\.pdf$/', $name) === 1; }
    private function sizeLabel(int $bytes): string { return rtrim(rtrim(number_format($bytes / 1048576, 1), '0'), '.') . ' MB'; }
    private function uploadError(int $error): string
    {
        if (in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) return 'The syllabus PDF is larger than the server upload limit.';
        if ($error === UPLOAD_ERR_PARTIAL) return 'The syllabus PDF was only partially uploaded. Try again.';
        if ($error === UPLOAD_ERR_NO_FILE) return 'Choose a PDF syllabus to upload.';
        return 'The syllabus PDF could not be uploaded. Try again.';
    }
}
