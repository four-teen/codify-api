<?php
declare(strict_types=1);

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/codify-api/health';
$_SERVER['SCRIPT_NAME'] = '/codify-api/index.php';

require dirname(__DIR__) . '/public/index.php';
