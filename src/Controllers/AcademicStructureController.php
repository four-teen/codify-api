<?php
declare(strict_types=1);

namespace Codify\Controllers;

use Codify\Core\HttpException;
use Codify\Core\Request;
use Codify\Core\Response;
use Codify\Repositories\AcademicStructureRepository;
use Codify\Services\AuthGuard;
use Codify\Support\Validator;

final class AcademicStructureController
{
    private $structures;
    private $guard;
    private const PARENTS = ['colleges' => 'campus_id', 'programs' => 'college_id', 'subjects' => 'program_id'];
    private const LABELS = ['campuses' => 'Campus', 'colleges' => 'College', 'programs' => 'Program', 'subjects' => 'Subject'];

    public function __construct(AcademicStructureRepository $structures, AuthGuard $guard)
    {
        $this->structures = $structures; $this->guard = $guard;
    }

    public function index(Request $request, string $resource): void
    {
        $this->guard->authenticate($request, true, 'administrator');
        $search = trim((string) $request->query('search', ''));
        if (strlen($search) > 120) throw new HttpException(422, 'The search value is too long.');
        Response::success($this->structures->all($resource, $search, $this->parentQuery($request, $resource)));
    }

    public function show(Request $request, string $resource): void
    {
        $this->guard->authenticate($request, true, 'administrator');
        Response::success($this->existing($resource, $this->id($request)));
    }

    public function store(Request $request, string $resource): void
    {
        $this->guard->authenticate($request, true, 'administrator');
        $values = $this->validated($resource, $request->json(), null);
        $record = $this->structures->create($resource, $values);
        Response::success($record, self::LABELS[$resource] . ' created successfully.', 201);
    }

    public function update(Request $request, string $resource): void
    {
        $this->guard->authenticate($request, true, 'administrator');
        $id = $this->id($request); $existing = $this->existing($resource, $id);
        $record = $this->structures->update($resource, $id, $this->validated($resource, $request->json(), $existing));
        Response::success($record, self::LABELS[$resource] . ' updated successfully.');
    }

    public function destroy(Request $request, string $resource): void
    {
        $this->guard->authenticate($request, true, 'administrator');
        $id = $this->id($request); $this->existing($resource, $id); $this->structures->delete($resource, $id);
        Response::success([], self::LABELS[$resource] . ' deleted successfully.');
    }

    private function validated(string $resource, array $input, ?array $existing): array
    {
        $validator = new Validator($input);
        $code = strtoupper($validator->requiredString('code', 50));
        $name = $validator->requiredString('name', 200);
        $active = $validator->boolean('is_active', $existing ? (bool) $existing['is_active'] : true);
        $values = ['code' => $code, 'name' => $name, 'is_active' => $active];
        $parentId = null;
        if (isset(self::PARENTS[$resource])) {
            $field = self::PARENTS[$resource];
            $raw = array_key_exists($field, $input) ? $input[$field] : ($existing[$field] ?? null);
            $parentId = filter_var($raw, FILTER_VALIDATE_INT);
            if ($parentId === false || $parentId < 1) $validator->add($field, 'A valid parent record is required.');
            else $values[$field] = (int) $parentId;
        }
        if ($resource === 'subjects') $values['units'] = $validator->integer('units', 0, 30, $existing ? (int) $existing['units'] : 3);
        $validator->throwIfFailed();
        if ($parentId !== null) $this->structures->assertParent($resource, (int) $parentId);
        $this->structures->assertUnique($resource, $code, $name, $parentId === null ? null : (int) $parentId, $existing['id'] ?? null);
        return $values;
    }

    private function parentQuery(Request $request, string $resource): ?int
    {
        if (!isset(self::PARENTS[$resource])) return null;
        $raw = $request->query(self::PARENTS[$resource]);
        if ($raw === null || $raw === '') return null;
        $id = filter_var($raw, FILTER_VALIDATE_INT);
        if ($id === false || $id < 1) throw new HttpException(422, 'The parent filter is invalid.');
        return (int) $id;
    }

    private function existing(string $resource, int $id): array
    {
        $record = $this->structures->find($resource, $id);
        if (!$record) throw new HttpException(404, self::LABELS[$resource] . ' not found.');
        return $record;
    }

    private function id(Request $request): int
    {
        $id = filter_var($request->route('record'), FILTER_VALIDATE_INT);
        if ($id === false || $id < 1) throw new HttpException(404, 'Academic record not found.');
        return (int) $id;
    }
}
