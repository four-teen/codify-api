<?php
declare(strict_types=1);

namespace Codify\Controllers;

use Codify\Core\HttpException;
use Codify\Core\Request;
use Codify\Core\Response;
use Codify\Repositories\AdministratorDataCleanupRepository;
use Codify\Services\AuthGuard;
use Codify\Services\SyllabusStorageService;

final class AdministratorDataCleanupController
{
    private $cleanup;
    private $guard;
    private $syllabus;

    public function __construct(AdministratorDataCleanupRepository $cleanup, AuthGuard $guard, SyllabusStorageService $syllabus)
    { $this->cleanup = $cleanup; $this->guard = $guard; $this->syllabus = $syllabus; }

    public function show(Request $request): void
    {
        $this->guard->authenticate($request, true, 'administrator');
        Response::success($this->cleanup->preview());
    }

    public function clear(Request $request): void
    {
        $actor = $this->guard->authenticate($request, true, 'administrator');
        $input = $request->json();
        $categories = $input['categories'] ?? null;
        if (!is_array($categories) || $categories === []) throw new HttpException(422, 'Select at least one data category to clear.', ['categories' => ['Select at least one category.']]);
        $categories = array_values(array_unique(array_map('strval', $categories)));
        $invalid = array_values(array_diff($categories, $this->cleanup->allowedCategories()));
        if ($invalid !== []) throw new HttpException(422, 'One or more selected data categories are invalid.', ['categories' => ['Refresh the page and select the categories again.']]);
        if (!hash_equals(AdministratorDataCleanupRepository::CONFIRMATION_PHRASE, trim((string) ($input['confirmation'] ?? '')))) {
            throw new HttpException(422, 'Enter the confirmation phrase exactly before clearing data.', ['confirmation' => ['Type ' . AdministratorDataCleanupRepository::CONFIRMATION_PHRASE . ' exactly.']]);
        }

        $result = $this->cleanup->clear($categories, (int) $actor['id']);
        foreach ($result['stored_names'] as $storedName) $this->syllabus->delete((string) $storedName);
        unset($result['stored_names']);
        Response::success($result, 'The selected data was cleared successfully. Administrator accounts, settings, permissions, and audit history were preserved.');
    }
}
