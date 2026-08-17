<?php
declare(strict_types=1);

return [
    'storage_path' => (string) env('SYLLABUS_STORAGE_PATH', dirname(__DIR__) . '/storage/syllabi'),
    'max_bytes' => max(1048576, min(52428800, (int) env('SYLLABUS_MAX_BYTES', '10485760'))),
];
