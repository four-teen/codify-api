<?php
declare(strict_types=1);

use Codify\Core\HttpException;
use Codify\Core\Request;
use Codify\Core\Router;
use Codify\Support\Validator;

require dirname(__DIR__) . '/bootstrap/autoload.php';

$validatorRejectedWeakPassword = false;
try {
    $validator = new Validator(['password' => 'short', 'password_confirmation' => 'short']);
    $validator->password(true);
    $validator->throwIfFailed();
} catch (HttpException $exception) {
    $validatorRejectedWeakPassword = $exception->status === 422;
}

if (!$validatorRejectedWeakPassword) {
    throw new RuntimeException('Weak-password validation smoke test failed.');
}

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/codify-api/api/v1/users/42';
$_SERVER['SCRIPT_NAME'] = '/codify-api/index.php';
$request = Request::capture();
$matchedId = null;
$router = new Router();
$router->get('/api/v1/users/{user}', static function (Request $request) use (&$matchedId): void {
    $matchedId = $request->route('user');
});
$router->dispatch($request);

if ($matchedId !== '42') {
    throw new RuntimeException('Parameterized-route smoke test failed.');
}

echo "Unit smoke tests passed.\n";
