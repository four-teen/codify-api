<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap/autoload.php';
$db = \Codify\Core\Connection::make();
$db->exec(file_get_contents(__DIR__ . '/python-written-attempts.sql'));
echo "Python written attempt tables are ready.\n";
