<?php
require dirname(__DIR__) . '/bootstrap/autoload.php';
\Codify\Core\Connection::make()->exec(file_get_contents(__DIR__ . '/python-rubrics.sql'));
echo "Python rubric tables ready.\n";
