<?php
declare(strict_types=1);

use Codify\Core\Connection;
use Codify\Repositories\AdministratorDataCleanupRepository;

require dirname(__DIR__) . '/bootstrap/autoload.php';

$repository = new AdministratorDataCleanupRepository(Connection::make());
$preview = $repository->preview();
$keys = array_column($preview['categories'], 'key');

foreach (['campuses', 'subjects', 'students', 'device_records', 'assessment_attempts'] as $required) {
    if (!in_array($required, $keys, true)) throw new RuntimeException('Missing cleanup category: ' . $required);
}
if ($preview['confirmation_phrase'] !== AdministratorDataCleanupRepository::CONFIRMATION_PHRASE) throw new RuntimeException('Cleanup confirmation phrase is inconsistent.');
if (!in_array('Administrator accounts', $preview['protected_data'], true)) throw new RuntimeException('Administrator accounts are not declared as protected.');

$campusDependencies = $repository->resolveCategories(['campuses']);
foreach (['campuses', 'colleges', 'programs', 'subjects', 'faculty_accounts', 'students', 'teaching_subjects', 'assessment_attempts', 'device_records'] as $dependent) {
    if (!in_array($dependent, $campusDependencies, true)) throw new RuntimeException('Campus cleanup is missing dependency: ' . $dependent);
}
if (in_array('authentication_history', $campusDependencies, true)) throw new RuntimeException('Campus cleanup unexpectedly includes independent authentication history.');

echo "Administrator data-cleanup preview smoke test passed without modifying data.\n";
