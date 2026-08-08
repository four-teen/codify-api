<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap/autoload.php';

use Codify\Core\Connection;

function db(): PDO
{
    static $connection = null;
    if (!$connection instanceof PDO) $connection = Connection::make();
    return $connection;
}
