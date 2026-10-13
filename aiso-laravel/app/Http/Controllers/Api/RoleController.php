<?php

namespace App\Http\Controllers\Api;

use App\Enums\Capability;
use App\Enums\DepartmentPermission;
use App\Enums\DepartmentRole;
use App\Http\Controllers\Controller;
use App\Services\DepartmentService;
use App\Services\RoleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The owner's roles and permissions: the company's roles (defined here), what each department role may do, and the list
 * of capabilities a role can hold.
 */
class RoleController extends Controller
{
    public function __construct(private readonly DepartmentService $departments, private readonly RoleService $roles)
    {
    }

    public function index(): JsonResponse
    {
        return response()->json([
            'roles' => $this->roles->list(),
            'capabilities' => Capability::grantable(),
            'departments' => $this->departments->permissionOverview(),
            'departmentRoles' => array_column(DepartmentRole::cases(), 'value'),
            'catalog' => array_column(DepartmentPermission::cases(), 'value'),
        ]);
    }

    /** The roles a person can be given: the key, the name, and whether they receive tasks. */
    public function assignable(): JsonResponse
    {
        return response()->json(collect($this->roles->list())->where('assignable', true)
            ->map(fn (array $r) => ['key' => $r['key'], 'name' => $r['name'], 'receivesTasks' => $r['receivesTasks']])
            ->values()->all());
    }

    public function store(Request $request): JsonResponse
    {
        $v = $this->input($request, [
            'name' => 'required|string|max:80',
            'description' => 'nullable|string|max:500',
            'receivesTasks' => 'nullable|boolean',
            'capabilities' => 'nullable|array',
            'capabilities.*' => 'string|max:60',
        ]);

        return response()->json($this->roles->create($v['name'], $v['description'] ?? null, (bool) ($v['receivesTasks'] ?? false),
            $v['capabilities'] ?? [], $this->actor($request)), 201);
    }

    public function update(Request $request, string $key): JsonResponse
    {
        $v = $this->input($request, [
            'name' => 'nullable|string|max:80',
            'description' => 'nullable|string|max:500',
            'receivesTasks' => 'nullable|boolean',
            'capabilities' => 'nullable|array',
            'capabilities.*' => 'string|max:60',
        ]);

        return response()->json($this->roles->update($key, $v['name'] ?? null, $v['description'] ?? null,
            isset($v['receivesTasks']) ? (bool) $v['receivesTasks'] : null, $v['capabilities'] ?? null, $this->actor($request)));
    }

    public function destroy(Request $request, string $key): JsonResponse
    {
        $this->roles->delete($key, $this->actor($request));

        return response()->json(null, 204);
    }
}
