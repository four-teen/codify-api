<?php
declare(strict_types=1);

use Codify\Controllers\FacultyTeachingController;
use Codify\Core\HttpException;

require dirname(__DIR__) . '/bootstrap/autoload.php';

$controller = (new ReflectionClass(FacultyTeachingController::class))->newInstanceWithoutConstructor();
$repair = new ReflectionMethod(FacultyTeachingController::class, 'repairImportedName');
$hasBrokenEncoding = new ReflectionMethod(FacultyTeachingController::class, 'hasBrokenNameEncoding');

$damagedUpper = (string) json_decode('"BE\uFFFDOLA, BRYAN DAVE E."');
$damagedLower = (string) json_decode('"Pe\uFFFDa, Juan"');
if ($repair->invoke($controller, $damagedUpper) !== "BE\xC3\x91OLA, BRYAN DAVE E.") throw new RuntimeException('Uppercase imported enye repair failed.');
if ($repair->invoke($controller, $damagedLower) !== "Pe\xC3\xB1a, Juan") throw new RuntimeException('Lowercase imported enye repair failed.');

$rejected = false;
try {
    $repair->invoke($controller, (string) json_decode('"\uFFFDBROKEN, STUDENT"'));
} catch (HttpException $exception) {
    $rejected = $exception->status === 422;
}
if (!$rejected) throw new RuntimeException('An unrecoverable imported name was not rejected.');
if (!$hasBrokenEncoding->invoke($controller, ['name' => $damagedUpper]) || $hasBrokenEncoding->invoke($controller, ['name' => "BE\xC3\x91OLA, BRYAN DAVE E."])) {
    throw new RuntimeException('Existing corrupted student-name detection failed.');
}

echo "Imported student-name encoding smoke test passed.\n";
