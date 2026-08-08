<?php
declare(strict_types=1);

namespace Codify\Support;

use Codify\Core\HttpException;

final class Validator
{
    /** @var array */
    private $input;
    /** @var array */
    private $errors = [];
    public function __construct(array $input) { $this->input = $input; }
    public function has(string $field): bool { return array_key_exists($field, $this->input); }

    public function requiredString(string $field, int $max): string
    {
        $value = trim((string) ($this->input[$field] ?? ''));
        if ($value === '') $this->add($field, 'The ' . str_replace('_', ' ', $field) . ' field is required.');
        elseif ($this->length($value) > $max) $this->add($field, 'The ' . str_replace('_', ' ', $field) . " field may not exceed {$max} characters.");
        return $value;
    }

    public function optionalString(string $field, int $max): ?string
    {
        if (!$this->has($field) || $this->input[$field] === null || trim((string) $this->input[$field]) === '') return null;
        $value = trim((string) $this->input[$field]);
        if ($this->length($value) > $max) $this->add($field, 'The ' . str_replace('_', ' ', $field) . " field may not exceed {$max} characters.");
        return $value;
    }

    public function email(string $field): string
    {
        $value = strtolower($this->requiredString($field, 255));
        if ($value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL) === false) $this->add($field, 'The email must be a valid email address.');
        return $value;
    }

    public function boolean(string $field, bool $default): bool
    {
        if (!$this->has($field)) return $default;
        $value = filter_var($this->input[$field], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($value === null) { $this->add($field, 'The ' . str_replace('_', ' ', $field) . ' field must be true or false.'); return $default; }
        return $value;
    }

    public function integer(string $field, int $min, int $max, int $default): int
    {
        if (!$this->has($field)) return $default;
        $value = filter_var($this->input[$field], FILTER_VALIDATE_INT);
        if ($value === false || $value < $min || $value > $max) { $this->add($field, "The {$field} field must be between {$min} and {$max}."); return $default; }
        return (int) $value;
    }

    public function oneOf(string $field, array $allowed, string $default = ''): string
    {
        $value = (string) ($this->input[$field] ?? $default);
        if (!in_array($value, $allowed, true)) $this->add($field, 'The selected ' . str_replace('_', ' ', $field) . ' is invalid.');
        return $value;
    }

    public function password(bool $required): ?string
    {
        $password = (string) ($this->input['password'] ?? '');
        if ($password === '' && !$required) return null;
        if ($password === '') $this->add('password', 'The password field is required.');
        elseif ($this->length($password) < 10 || preg_match('/[A-Za-z]/', $password) !== 1 || preg_match('/\d/', $password) !== 1) $this->add('password', 'The password must be at least 10 characters and contain letters and numbers.');
        if ($password !== (string) ($this->input['password_confirmation'] ?? '')) $this->add('password', 'The password confirmation does not match.');
        return $password;
    }

    public function add(string $field, string $message): void { $this->errors[$field][] = $message; }
    public function throwIfFailed(): void { if ($this->errors !== []) throw new HttpException(422, 'The supplied data is invalid.', $this->errors); }
    private function length(string $value): int { return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value); }
}
