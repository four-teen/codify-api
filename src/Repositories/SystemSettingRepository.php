<?php
declare(strict_types=1);

namespace Codify\Repositories;

use PDO;

final class SystemSettingRepository
{
    /** @var PDO */
    private $db;
    private const BOOLEAN_FIELDS = ['faculty_student_management_enabled', 'temporary_password_change_required', 'maintenance_mode', 'announcement_enabled'];
    private const INTEGER_FIELDS = ['id', 'session_timeout_minutes', 'max_failed_login_attempts'];

    public function __construct(PDO $db) { $this->db = $db; }

    public function current(): array
    {
        $row = $this->db->query('SELECT * FROM system_settings ORDER BY id LIMIT 1')->fetch();
        if (!$row) {
            $year = (int) date('Y');
            $statement = $this->db->prepare('INSERT INTO system_settings (id, institution_name, institution_logo_url, timezone, academic_year, academic_term, faculty_student_management_enabled, temporary_password_change_required, session_timeout_minutes, max_failed_login_attempts, maintenance_mode, announcement_enabled, announcement_message, created_at, updated_at) VALUES (1, :name, :logo, :timezone, :year, :term, 1, 1, 120, 5, 0, 0, NULL, NOW(), NOW())');
            $statement->execute(['name' => 'Codify', 'logo' => '/codify-logo-official.png', 'timezone' => 'Asia/Manila', 'year' => $year . '-' . ($year + 1), 'term' => 'First Semester']);
            $row = $this->db->query('SELECT * FROM system_settings WHERE id = 1')->fetch();
        }
        return $this->cast($row ?: []);
    }

    public function publicPayload(): array
    {
        $settings = $this->current();
        return [
            'institution_name' => $settings['institution_name'],
            'institution_logo_url' => $settings['institution_logo_url'],
            'timezone' => $settings['timezone'],
            'academic_year' => $settings['academic_year'],
            'academic_term' => $settings['academic_term'],
            'maintenance_mode' => $settings['maintenance_mode'],
            'announcement_enabled' => $settings['announcement_enabled'],
            'announcement_message' => $settings['announcement_enabled'] ? $settings['announcement_message'] : null,
        ];
    }

    public function update(array $values): array
    {
        $allowed = ['institution_name', 'institution_logo_url', 'timezone', 'academic_year', 'academic_term', 'faculty_student_management_enabled', 'temporary_password_change_required', 'session_timeout_minutes', 'max_failed_login_attempts', 'maintenance_mode', 'announcement_enabled', 'announcement_message'];
        $sets = [];
        $parameters = [];
        foreach ($allowed as $field) {
            if (!array_key_exists($field, $values)) continue;
            $sets[] = "{$field} = :{$field}";
            $parameters[$field] = in_array($field, self::BOOLEAN_FIELDS, true) ? ($values[$field] ? 1 : 0) : $values[$field];
        }
        if ($sets !== []) {
            $sets[] = 'updated_at = NOW()';
            $statement = $this->db->prepare('UPDATE system_settings SET ' . implode(', ', $sets) . ' WHERE id = 1');
            $statement->execute($parameters);
        }
        return $this->current();
    }

    private function cast(array $row): array
    {
        foreach (self::BOOLEAN_FIELDS as $field) if (array_key_exists($field, $row)) $row[$field] = (bool) $row[$field];
        foreach (self::INTEGER_FIELDS as $field) if (array_key_exists($field, $row)) $row[$field] = (int) $row[$field];
        return $row;
    }
}
