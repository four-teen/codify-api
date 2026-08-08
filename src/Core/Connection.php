<?php
declare(strict_types=1);

namespace Codify\Core;

use PDO;

final class Connection
{
    public static function make(): PDO
    {
        $host = env('DB_HOST', 'localhost'); $port = env('DB_PORT', '3306');
        $name = env('DB_NAME', env('DB_DATABASE', 'codify_db')); $user = env('DB_USER', env('DB_USERNAME', 'root')); $password = env('DB_PASS', env('DB_PASSWORD', ''));
        return new PDO("mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4", $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
}
