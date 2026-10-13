<?php

namespace App\Http\Controllers\Api;

use App\Enums\DepartmentPermission;
use App\Enums\DepartmentRole;
use App\Enums\DepartmentStatus;
use App\Enums\SuggestionStatus;
use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Services\DepartmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Departments. The panels (index, store, show, and the owner's changes) are routed to the owner only. Resource edits and
 * suggestions are also open to members whose department role holds the permission; DepartmentService decides that.
 */
class DepartmentController extends Controller
{
    public function __construct(private readonly DepartmentService $departments)
    {
    }

    // ---- owner: panels ------------------------------------------------------------------------------------------

    public function index(): JsonResponse
    {
        return response()->json($this->departments->list());
    }

    public function store(Request $request): JsonResponse
    {
        $v = $this->input($request, [
            'name' => 'required|string|max:200',
            'description' => 'nullable|string|max:1000',
        ]);

        return response()->json($this->departments->create($v['name'], $v['description'] ?? null, $this->actor($request)));
    }

    /** The panel: the owner, or a member whose role holds PANEL_VIEW (see DepartmentService::detail). */
    public function show(Request $request, string $id): JsonResponse
    {
        return response()->json($this->departments->detail($id, $this->actor($request)));
    }

    /** The import history, in the production department's panel. */
    public function imports(Request $request, string $id): JsonResponse
    {
        return response()->json($this->departments->imports($id, $this->actor($request)));
    }

    /** The audit log, in the human resources panel (owner only). */
    public function audit(Request $request, string $id): JsonResponse
    {
        $v = $this->input($request, ['limit' => 'nullable|integer|min:1|max:500']);

        return response()->json($this->departments->audit($id, (int) ($v['limit'] ?? 300), $this->actor($request)));
    }

    /** The departments in the main menu of the person asking. */
    public function menu(Request $request): JsonResponse
    {
        return response()->json($this->departments->menu($this->actor($request)));
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $v = $this->input($request, [
            'name' => 'nullable|string|max:200',
            'description' => 'nullable|string|max:1000',
            'status' => ['nullable', Rule::in(array_column(DepartmentStatus::cases(), 'value'))],
            'code' => ['nullable', Rule::in(['', Department::HR, Department::PRODUCTION])],
        ]);

        // a description sent empty clears it; an absent one is left as it is
        $description = $request->has('description') ? (string) ($v['description'] ?? '') : null;

        // the special role is changed only when it is sent; sent empty, it is taken away
        $code = $request->has('code') ? (string) ($v['code'] ?? '') : null;

        return response()->json($this->departments->update($id, $v['name'] ?? null, $description,
            isset($v['status']) ? DepartmentStatus::from($v['status']) : null, $code, $this->actor($request)));
    }

    public function members(Request $request, string $id): JsonResponse
    {
        $v = $this->input($request, [
            'members' => 'present|array',
            'members.*.userId' => 'required|string|max:64',
            'members.*.role' => ['required', Rule::in(array_column(DepartmentRole::cases(), 'value'))],
        ]);
        $members = array_map(static fn (array $m) => [
            'userId' => $m['userId'],
            'role' => DepartmentRole::from($m['role']),
        ], $v['members']);

        return response()->json($this->departments->setMembers($id, $members, $this->actor($request)));
    }

    public function permissions(Request $request, string $id): JsonResponse
    {
        $catalog = array_column(DepartmentPermission::cases(), 'value');
        $v = $this->input($request, [
            'permissions' => 'required|array',
            'permissions.HEAD' => 'present|array',
            'permissions.HEAD.*' => [Rule::in($catalog)],
            'permissions.MEMBER' => 'present|array',
            'permissions.MEMBER.*' => [Rule::in($catalog)],
        ]);

        return response()->json($this->departments->setPermissions($id, $v['permissions'], $this->actor($request)));
    }

    public function assignResources(Request $request, string $id): JsonResponse
    {
        $v = $this->input($request, [
            'resourceIds' => 'present|array',
            'resourceIds.*' => 'required|string|max:64',
        ]);

        return response()->json($this->departments->assignResources($id, $v['resourceIds'], $this->actor($request)));
    }

    // ---- owner, or a member whose department role allows it ---------------------------------------------------

    public function updateResource(Request $request, string $id, string $resourceId): JsonResponse
    {
        $v = $this->input($request, [
            'name' => 'nullable|string|max:200',
            'capacity' => 'nullable|integer|min:1|max:100000',
            'status' => ['nullable', Rule::in(['ACTIVE', 'INACTIVE'])],
            'description' => 'nullable|string|max:1000',
        ]);

        $fields = array_filter($v, static fn ($value) => $value !== null);
        // a description sent empty clears it; an absent one is left as it is
        if ($request->has('description')) {
            $fields['description'] = (string) ($v['description'] ?? '');
        }

        return response()->json($this->departments->updateResource($id, $resourceId, $fields, $this->actor($request)));
    }

    public function submitSuggestion(Request $request, string $id): JsonResponse
    {
        $v = $this->input($request, [
            'title' => 'required|string|max:200',
            'body' => 'required|string|max:2000',
            'resourceId' => 'nullable|string|max:64',
        ]);

        return response()->json($this->departments->submitSuggestion($id, $v['title'], $v['body'],
            $v['resourceId'] ?? null, $this->actor($request)));
    }

    // ---- owner and manager: suggestions on the dashboard --------------------------------------------------------

    public function suggestions(Request $request): JsonResponse
    {
        $v = $this->input($request, [
            'status' => ['nullable', Rule::in(array_column(SuggestionStatus::cases(), 'value'))],
        ]);

        return response()->json($this->departments->suggestions(
            isset($v['status']) ? SuggestionStatus::from($v['status']) : null,
        ));
    }

    public function decide(Request $request, string $id): JsonResponse
    {
        $v = $this->input($request, [
            'decision' => ['required', Rule::in(['ACCEPTED', 'REJECTED'])],
            'note' => 'nullable|string|max:1000',
        ]);

        return response()->json($this->departments->decide((int) $id, SuggestionStatus::from($v['decision']),
            $v['note'] ?? null, $this->actor($request)));
    }

    /** Old address of the user management (/owner/users): it is the human resources panel now. */
    public function legacyUsers(): RedirectResponse
    {
        $hr = Department::query()->where('code', Department::HR)->first();

        return $hr === null
            ? redirect('/owner')
            : redirect(route('departments.show', ['id' => $hr->id]) . '?tab=users');
    }

    /** Old address of the audit log (/audit): the human resources panel, its audit tab. */
    public function legacyAudit(): RedirectResponse
    {
        return $this->redirectToTab(Department::HR, 'audit');
    }

    /** Old address of the import history (/import): the production panel, its imports tab. */
    public function legacyImports(): RedirectResponse
    {
        return $this->redirectToTab(Department::PRODUCTION, 'imports');
    }

    private function redirectToTab(string $code, string $tab): RedirectResponse
    {
        $d = Department::query()->where('code', $code)->first();

        return $d === null
            ? redirect('/owner')
            : redirect(route('departments.show', ['id' => $d->id]) . '?tab=' . $tab);
    }
}
