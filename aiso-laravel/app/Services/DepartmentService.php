<?php

namespace App\Services;

use App\Enums\DepartmentPermission;
use App\Enums\DepartmentRole;
use App\Enums\DepartmentStatus;
use App\Models\AppRole;
use App\Enums\SuggestionStatus;
use App\Exceptions\ApiException;
use App\Models\AppUser;
use App\Models\AuditEvent;
use App\Models\Department;
use App\Models\DepartmentMember;
use App\Models\DepartmentRolePermission;
use App\Models\DepartmentSuggestion;
use App\Models\ImportLog;
use App\Models\Operation;
use App\Models\WorkResource;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Departments. A department's panel is open to the owner and to members whose department role holds PANEL_VIEW. The
 * owner alone sets who belongs to a department, what each role may do, the department itself, and the user management
 * that lives in the human resources department. Members with the permissions can keep the department's resources up
 * to date and send suggestions to the manager. A resource that has not been confirmed within the review period is stale.
 *
 * The rules live here, not in views: the API answers 403 to anyone without the permission, whatever the menu shows.
 */
class DepartmentService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly NotificationService $notifications,
    ) {
    }

    // ---- the menu and the list ----------------------------------------------------------------------------------

    /**
     * The departments this person may open, for the main menu. The owner gets all of them. Everyone else gets the
     * departments where their role holds PANEL_VIEW. A fixed number of queries, whatever the number of departments.
     *
     * @return array<int, array<string, mixed>>
     */
    public function menu(AppUser $actor): array
    {
        $q = Department::query()->orderBy('sort_order')->orderBy('id');
        $headOf = [];

        if (!$actor->isOwner()) {
            $memberships = DepartmentMember::query()->where('user_id', $actor->id)->get(['department_id', 'role']);
            $headOf = $memberships->filter(fn (DepartmentMember $m) => $m->role === DepartmentRole::HEAD)
                ->pluck('department_id')->map(fn ($id) => (int) $id)->all();
            if ($memberships->isEmpty()) {
                return [];
            }
            $granted = DepartmentRolePermission::query()
                ->where('permission', DepartmentPermission::PANEL_VIEW->value)
                ->whereIn('department_id', $memberships->pluck('department_id'))
                ->get(['department_id', 'role']);

            $ids = $memberships->filter(fn (DepartmentMember $m) => $granted->contains(
                fn (DepartmentRolePermission $g) => (int) $g->department_id === (int) $m->department_id && $g->role === $m->role,
            ))->pluck('department_id');
            if ($ids->isEmpty()) {
                return [];
            }
            $q->whereIn('id', $ids);
        }

        return $q->get()->map(fn (Department $d) => [
            'id' => $d->id,
            'name' => $d->name,
            'code' => $d->code,
            'status' => $d->status->value,
            'isHr' => $d->isHr(),
            'headOf' => in_array((int) $d->id, $headOf, true),
        ])->values()->all();
    }

    /**
     * The owner's directory of every department: the summary, the head(s), and a small operational summary where the
     * department has one (human resources: active users; production: operations by state). A fixed number of queries.
     *
     * @return array<int, array<string, mixed>>
     */
    public function list(): array
    {
        $now = CarbonImmutable::now();
        $heads = DepartmentMember::query()->where('role', DepartmentRole::HEAD->value)->with('user')->get()
            ->groupBy('department_id');
        $departments = Department::query()->orderBy('sort_order')->orderBy('id')->get();

        $operations = $departments->contains(fn (Department $d) => $d->isProduction())
            ? Operation::query()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')
            : collect();
        $activeUsers = $departments->contains(fn (Department $d) => $d->isHr())
            ? AppUser::query()->people()->where('active', true)->count()
            : 0;

        $counts = [
            'members' => DepartmentMember::query()->selectRaw('department_id, count(*) as n')
                ->groupBy('department_id')->pluck('n', 'department_id'),
            'resources' => WorkResource::query()->whereNotNull('department_id')->get()->groupBy('department_id'),
            'pending' => DepartmentSuggestion::query()->where('status', SuggestionStatus::PENDING->value)
                ->selectRaw('department_id, count(*) as n')->groupBy('department_id')->pluck('n', 'department_id'),
        ];

        return $departments->map(fn (Department $d) => $this->summary($d, $now, $counts) + [
            'heads' => ($heads->get($d->id) ?? collect())->map(fn (DepartmentMember $m) => [
                'userId' => $m->user_id,
                'fullName' => $m->user?->full_name,
            ])->values()->all(),
            'metrics' => match (true) {
                $d->isHr() => ['activeUsers' => $activeUsers],
                $d->isProduction() => [
                    'ready' => (int) ($operations['READY'] ?? 0),
                    'inProgress' => (int) ($operations['ASSIGNED'] ?? 0) + (int) ($operations['IN_PROGRESS'] ?? 0),
                    'blocked' => (int) ($operations['BLOCKED'] ?? 0),
                ],
                default => null,
            },
        ])->values()->all();
    }

    // ---- the panel ----------------------------------------------------------------------------------------------

    /**
     * The panel of one department, shaped for the viewer. Everyone who may open it gets the members, resources and
     * suggestions, plus `abilities` that say what they may do. Only the owner also gets the permission matrix and the
     * resource choices; the user management is in `abilities.viewUsers` (human resources, owner only).
     */
    public function detail(int|string $id, AppUser $actor): array
    {
        $d = self::find($id);
        $this->assertCanView($actor, $d);
        $now = CarbonImmutable::now();
        $owner = $actor->isOwner();

        $view = $this->summary($d, $now) + [
            'isHr' => $d->isHr(),
            'myRole' => $owner ? null : $this->roleOf($actor, $d)?->value,
            'abilities' => $this->abilities($actor, $d),
            'members' => $d->members()->with('user')->get()
                ->sortBy(fn (DepartmentMember $m) => [$m->role === DepartmentRole::HEAD ? 0 : 1, $m->user?->full_name ?? ''])
                ->values()
                ->map(fn (DepartmentMember $m) => [
                    'userId' => $m->user_id,
                    'fullName' => $m->user?->full_name,
                    'role' => $m->role->value,
                    'active' => (bool) $m->user?->isActive(),
                ])->all(),
            'resources' => $d->resources()->orderBy('sort_order')->orderBy('id')->get()
                ->map(fn (WorkResource $r) => $this->resourceView($r, $now))->values()->all(),
            'suggestions' => $d->suggestions()->with(['department', 'author', 'resource'])
                ->orderByDesc('created_at')->orderByDesc('id')->limit(50)->get()
                ->map(fn (DepartmentSuggestion $s) => $this->suggestionView($s))->values()->all(),
            'reviewDays' => $this->reviewDays(),
        ];

        if ($owner) {
            $view += [
                'permissions' => $this->permissionMatrix($d),
                'permissionCatalog' => array_column(DepartmentPermission::cases(), 'value'),
                'allResources' => WorkResource::inEnteredOrder()->with('department')->get()
                    ->map(fn (WorkResource $r) => [
                        'id' => $r->id,
                        'name' => $r->name,
                        'departmentId' => $r->department_id,
                        'departmentName' => $r->department?->name,
                    ])->values()->all(),
            ];
        }

        return $view;
    }

    public function create(string $name, ?string $description, AppUser $actor): array
    {
        return DB::transaction(function () use ($name, $description, $actor) {
            $d = Department::query()->create([
                'name' => trim($name),
                'description' => self::clean($description),
                'status' => DepartmentStatus::ACTIVE,
                'sort_order' => (int) Department::query()->max('sort_order') + 1,
                'created_at' => CarbonImmutable::now(),
            ]);

            $rows = [];
            foreach (DepartmentRole::cases() as $role) {
                foreach (DepartmentPermission::defaults($role) as $permission) {
                    $rows[] = ['department_id' => $d->id, 'role' => $role->value, 'permission' => $permission->value];
                }
            }
            DepartmentRolePermission::query()->insert($rows);

            $this->audit->record($actor->id, 'DEPARTMENT_CREATED', 'Department', (string) $d->id, null, $d->name, null);

            return $this->detail($d->id, $actor);
        });
    }

    /**
     * $code is the department's special role (HR, PRODUCTION); null leaves it, '' takes it away. Only one department
     * can hold a special role.
     */
    public function update(int|string $id, ?string $name, ?string $description, ?DepartmentStatus $status,
                           ?string $code, AppUser $actor): array
    {
        return DB::transaction(function () use ($id, $name, $description, $status, $code, $actor) {
            $d = self::find($id);
            $before = $d->name . ' / ' . $d->status->value . ' / ' . ($d->code ?? '-');

            if ($name !== null && trim($name) !== '') {
                $d->name = trim($name);
            }
            if ($description !== null) {
                $d->description = self::clean($description);
            }
            if ($status !== null) {
                $d->status = $status;
            }
            if ($code !== null) {
                $newCode = self::clean($code);
                if ($newCode !== null && Department::query()->where('code', $newCode)->whereKeyNot($d->id)->exists()) {
                    throw ApiException::conflict('This special role is already held by another department');
                }
                $d->code = $newCode;
            }
            $d->save();

            $this->audit->record($actor->id, 'DEPARTMENT_UPDATED', 'Department', (string) $d->id,
                $before, $d->name . ' / ' . $d->status->value . ' / ' . ($d->code ?? '-'), null);

            return $this->detail($d->id, $actor);
        });
    }

    /**
     * Replaces the members of a department. The owner is not a member (sees every department already). A user who is
     * inactive can stay a member but cannot be added.
     *
     * @param array<int, array{userId: string, role: DepartmentRole}> $members
     */
    public function setMembers(int|string $id, array $members, AppUser $actor): array
    {
        return DB::transaction(function () use ($id, $members, $actor) {
            $d = self::find($id);
            $before = $this->memberText($d->members()->get());
            $current = $d->members()->pluck('user_id')->all();

            $rows = [];
            $seen = [];
            foreach ($members as $m) {
                $user = AppUser::query()->find($m['userId']);
                if ($user === null) {
                    throw ApiException::badRequest("User {$m['userId']} not found");
                }
                if ($user->isOwner()) {
                    throw ApiException::badRequest('The owner sees every department and is not a member of one');
                }
                if ($user->isAgent()) {
                    throw ApiException::badRequest('An AI agent reads the departments it was given; it is not a member of one');
                }
                if (isset($seen[$user->id])) {
                    throw ApiException::badRequest("User {$user->id} is listed more than once");
                }
                if (!$user->isActive() && !in_array($user->id, $current, true)) {
                    throw ApiException::badRequest("User {$user->id} is inactive");
                }
                $seen[$user->id] = true;
                $rows[] = ['department_id' => $d->id, 'user_id' => $user->id, 'role' => $m['role']->value];
            }

            $d->members()->delete();
            if ($rows !== []) {
                DepartmentMember::query()->insert($rows);
            }

            $this->audit->record($actor->id, 'DEPARTMENT_MEMBERS_UPDATED', 'Department', (string) $d->id,
                $before, $this->memberText($d->members()->get()), null);

            return $this->detail($d->id, $actor);
        });
    }

    /**
     * Sets what each department role may do. A role missing from the matrix holds no permission.
     *
     * @param array<string, string[]> $matrix department role value => permission values
     */
    public function setPermissions(int|string $id, array $matrix, AppUser $actor): array
    {
        return DB::transaction(function () use ($id, $matrix, $actor) {
            $d = self::find($id);
            $before = $this->matrixText($this->permissionMatrix($d));

            $rows = [];
            foreach (DepartmentRole::cases() as $role) {
                $granted = array_values(array_unique($matrix[$role->value] ?? []));
                foreach ($granted as $permission) {
                    $rows[] = [
                        'department_id' => $d->id,
                        'role' => $role->value,
                        'permission' => DepartmentPermission::from($permission)->value,
                    ];
                }
            }

            DepartmentRolePermission::query()->where('department_id', $d->id)->delete();
            if ($rows !== []) {
                DepartmentRolePermission::query()->insert($rows);
            }

            $this->audit->record($actor->id, 'DEPARTMENT_PERMISSIONS_UPDATED', 'Department', (string) $d->id,
                $before, $this->matrixText($this->permissionMatrix($d)), null);

            return $this->detail($d->id, $actor);
        });
    }

    /**
     * Makes the given resources this department's. Resources it had and that are not listed are released. A resource
     * that belonged to another department moves here and is named in the audit trail.
     *
     * @param string[] $resourceIds
     */
    public function assignResources(int|string $id, array $resourceIds, AppUser $actor): array
    {
        return DB::transaction(function () use ($id, $resourceIds, $actor) {
            $d = self::find($id);
            $ids = array_values(array_unique($resourceIds));

            if (WorkResource::query()->whereIn('id', $ids)->count() !== count($ids)) {
                throw ApiException::badRequest('One or more resources were not found');
            }

            $before = $d->resources()->pluck('id')->implode(', ');
            $moved = WorkResource::query()->whereIn('id', $ids)->whereNotNull('department_id')
                ->where('department_id', '!=', $d->id)->pluck('id')->implode(', ');

            WorkResource::query()->where('department_id', $d->id)->whereNotIn('id', $ids)->update(['department_id' => null]);
            WorkResource::query()->whereIn('id', $ids)->update(['department_id' => $d->id]);

            $this->audit->record($actor->id, 'DEPARTMENT_RESOURCES_ASSIGNED', 'Department', (string) $d->id,
                $before, implode(', ', $ids), $moved === '' ? null : "Moved from another department: {$moved}");

            return $this->detail($d->id, $actor);
        });
    }

    // ---- the work of a department: the owner, or a member whose role allows it -----------------------------------

    /**
     * Saves a resource of the department. Every save confirms that the resource is up to date.
     *
     * @param array{name?: ?string, capacity?: ?int, status?: ?string, description?: ?string} $fields
     */
    public function updateResource(int|string $id, string $resourceId, array $fields, AppUser $actor): array
    {
        return DB::transaction(function () use ($id, $resourceId, $fields, $actor) {
            $d = self::find($id);
            $this->assertCan($actor, $d, DepartmentPermission::RESOURCES_MANAGE);
            self::assertActive($d);

            $r = self::departmentResource($d, $resourceId);
            $before = self::describeResource($r);

            if (isset($fields['name']) && trim($fields['name']) !== '') {
                $r->name = trim($fields['name']);
            }
            if (isset($fields['capacity'])) {
                $r->capacity = (int) $fields['capacity'];
            }
            if (isset($fields['status'])) {
                $r->status = $fields['status'];
            }
            if (isset($fields['description'])) {
                $r->description = self::clean($fields['description']);
            }
            $r->reviewed_at = CarbonImmutable::now();
            $r->save();

            $this->audit->record($actor->id, 'RESOURCE_UPDATED', 'Resource', $r->id,
                $before, self::describeResource($r), "Department {$d->name}");

            return $this->resourceView($r, CarbonImmutable::now());
        });
    }

    /** A suggestion from the department to the manager. The manager decides on the manager dashboard. */
    public function submitSuggestion(int|string $id, string $title, string $body, ?string $resourceId,
                                     AppUser $actor): array
    {
        return DB::transaction(function () use ($id, $title, $body, $resourceId, $actor) {
            $d = self::find($id);
            $this->assertCan($actor, $d, DepartmentPermission::SUGGESTIONS_SUBMIT);
            self::assertActive($d);

            if ($resourceId !== null) {
                self::departmentResource($d, $resourceId);
            }

            $s = DepartmentSuggestion::query()->create([
                'department_id' => $d->id,
                'author_id' => $actor->id,
                'resource_id' => $resourceId,
                'title' => trim($title),
                'body' => trim($body),
                'status' => SuggestionStatus::PENDING,
                'created_at' => CarbonImmutable::now(),
            ]);

            $this->audit->record($actor->id, 'DEPARTMENT_SUGGESTION_SUBMITTED', 'DepartmentSuggestion', (string) $s->id,
                null, $s->title, "Department {$d->name}");
            $this->notifications->notifyManagers('DEPARTMENT_SUGGESTION',
                "New suggestion from the department {$d->name}: {$s->title}", null);

            return $this->suggestionView($s->load(['department', 'author', 'resource']));
        });
    }

    // ---- manager: suggestions on the dashboard ------------------------------------------------------------------

    /** @return array<int, array<string, mixed>> newest first */
    public function suggestions(?SuggestionStatus $status): array
    {
        $q = DepartmentSuggestion::query()->with(['department', 'author', 'resource'])
            ->orderByDesc('created_at')->orderByDesc('id');
        if ($status !== null) {
            $q->where('status', $status->value);
        }

        return $q->limit(100)->get()->map(fn (DepartmentSuggestion $s) => $this->suggestionView($s))->values()->all();
    }

    public function decide(int $suggestionId, SuggestionStatus $decision, ?string $note, AppUser $actor): array
    {
        return DB::transaction(function () use ($suggestionId, $decision, $note, $actor) {
            $s = DepartmentSuggestion::query()->with(['department', 'author', 'resource'])
                ->lockForUpdate()->find($suggestionId);
            if ($s === null) {
                throw ApiException::notFound("Suggestion {$suggestionId} not found");
            }
            if (!$s->status->isOpen()) {
                throw ApiException::conflict('This suggestion has already been decided');
            }
            if ($decision->isOpen()) {
                throw ApiException::badRequest('A suggestion is accepted or rejected');
            }

            $s->status = $decision;
            $s->decided_by = $actor->id;
            $s->decided_at = CarbonImmutable::now();
            $s->manager_note = self::clean($note);
            $s->save();

            $this->audit->record($actor->id, 'DEPARTMENT_SUGGESTION_DECIDED', 'DepartmentSuggestion', (string) $s->id,
                SuggestionStatus::PENDING->value, $decision->value, $s->manager_note);
            if ($s->author_id !== $actor->id) {
                $verb = $decision === SuggestionStatus::ACCEPTED ? 'accepted' : 'rejected';
                $this->notifications->notifyUser($s->author_id, 'DEPARTMENT_SUGGESTION_DECIDED',
                    "Your suggestion «{$s->title}» was {$verb} by the manager", null);
            }

            return $this->suggestionView($s);
        });
    }

    /**
     * The import history, in the production department's panel. Same rows as the old import page, newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function imports(int|string $id, AppUser $actor): array
    {
        $d = self::find($id);
        $this->assertCanView($actor, $d);
        if (!$d->isProduction()) {
            throw ApiException::notFound("Department {$d->name} has no import history");
        }

        return ImportLog::query()->orderByDesc('imported_at')->orderByDesc('id')->limit(50)->get()
            ->map(static fn (ImportLog $l) => [
                'id' => $l->id,
                'importedAt' => self::iso($l->imported_at),
                'actorId' => $l->actor_id,
                'fileName' => $l->file_name,
                'format' => $l->file_format,
                'status' => $l->status,
                'errorCount' => (int) $l->error_count,
                'summary' => $l->summary,
                'details' => $l->details,
            ])->values()->all();
    }

    /**
     * The audit log, in the human resources panel. Only the owner reads it: it holds every change in the system.
     *
     * @return array<int, array<string, mixed>>
     */
    public function audit(int|string $id, int $limit, AppUser $actor): array
    {
        $d = self::find($id);
        $this->assertCanView($actor, $d);
        if (!$this->abilities($actor, $d)['viewAudit']) {
            throw ApiException::forbidden('Only the owner reads the audit log');
        }

        return AuditEvent::query()->orderByDesc('occurred_at')->limit($limit)->get()
            ->map(fn (AuditEvent $e) => DashboardService::auditView($e))->values()->all();
    }

    // ---- permission checks --------------------------------------------------------------------------------------

    /** Opening a panel needs PANEL_VIEW (the owner always may). */
    public function assertCanView(AppUser $actor, Department $d): void
    {
        if (!$this->grants($actor, $d, DepartmentPermission::PANEL_VIEW)) {
            throw ApiException::forbidden('You do not have access to this department');
        }
    }

    /** The owner may do everything. Anyone else must be a member whose department role holds the permission. */
    public function assertCan(AppUser $actor, Department $d, DepartmentPermission $permission): void
    {
        if ($actor->isOwner()) {
            return;
        }
        if ($this->roleOf($actor, $d) === null) {
            throw ApiException::forbidden('You are not a member of this department');
        }
        if (!$this->grants($actor, $d, $permission)) {
            throw ApiException::forbidden('Your department role does not allow this');
        }
    }

    /** What this person may do in this department, for the panel's buttons. The server checks the same rules. */
    private function abilities(AppUser $actor, Department $d): array
    {
        $owner = $actor->isOwner();
        $active = $d->isActive();

        return [
            'manageDepartment' => $owner,
            'manageMembers' => $owner,
            'managePermissions' => $owner,
            'assignResources' => $owner,
            'viewUsers' => $owner && $d->isHr(),
            'viewAudit' => $owner && $d->isHr(),
            'viewAgents' => $owner && $d->isHr(),
            'viewImports' => $d->isProduction() && $this->grants($actor, $d, DepartmentPermission::PANEL_VIEW),
            'viewManagerDashboard' => $this->showsManagerDashboard($actor, $d),
            // projects, assignment, operations, plan builder, plan and reports: the production workspace, for the owner
            // and for managers who belong to it (the APIs behind them are owner and manager only)
            'viewPlanning' => $d->isProduction()
                && ($actor->isOwner() || $actor->hasCapability('production.view'))
                && $this->grants($actor, $d, DepartmentPermission::PANEL_VIEW),
            'manageResources' => $active && $this->grants($actor, $d, DepartmentPermission::RESOURCES_MANAGE),
            'submitSuggestions' => $active && $this->grants($actor, $d, DepartmentPermission::SUGGESTIONS_SUBMIT),
        ];
    }

    private function grants(AppUser $actor, Department $d, DepartmentPermission $permission): bool
    {
        if ($actor->isOwner()) {
            return true;
        }
        $role = $this->roleOf($actor, $d);
        if ($role === null) {
            return false;
        }

        return DepartmentRolePermission::query()->where('department_id', $d->id)
            ->where('role', $role->value)->where('permission', $permission->value)->exists();
    }

    /**
     * The manager dashboard is shown in the panel of the department the manager heads. The department must have a
     * manager as its head; the owner and that manager see the dashboard there.
     */
    private function showsManagerDashboard(AppUser $actor, Department $d): bool
    {
        $managerHead = DepartmentMember::query()->where('department_id', $d->id)
            ->where('role', DepartmentRole::HEAD->value)
            ->whereHas('user', fn ($q) => $q->whereIn('user_role', AppRole::keysWith('production.manage')))
            ->exists();
        if (!$managerHead) {
            return false;
        }

        return $actor->isOwner()
            || ($actor->hasCapability('production.manage') && $this->roleOf($actor, $d) === DepartmentRole::HEAD);
    }

    private function roleOf(AppUser $actor, Department $d): ?DepartmentRole
    {
        return DepartmentMember::query()->where('department_id', $d->id)->where('user_id', $actor->id)
            ->first()?->role;
    }

    // ---- helpers ------------------------------------------------------------------------------------------------

    public function isStale(WorkResource $r, CarbonImmutable $now): bool
    {
        return $r->reviewed_at === null
            || CarbonImmutable::instance($r->reviewed_at)->lt($now->subDays($this->reviewDays()));
    }

    private function reviewDays(): int
    {
        return max(1, (int) config('aiso.departments.review_days'));
    }

    private static function find(int|string $id): Department
    {
        $d = Department::query()->find($id);
        if ($d === null) {
            throw ApiException::notFound("Department {$id} not found");
        }

        return $d;
    }

    private static function assertActive(Department $d): void
    {
        if (!$d->isActive()) {
            throw ApiException::conflict("Department {$d->name} is inactive");
        }
    }

    private static function departmentResource(Department $d, string $resourceId): WorkResource
    {
        $r = WorkResource::query()->find($resourceId);
        if ($r === null || (int) $r->department_id !== (int) $d->id) {
            throw ApiException::notFound("Resource {$resourceId} is not assigned to department {$d->name}");
        }

        return $r;
    }

    private static function clean(?string $text): ?string
    {
        $text = $text === null ? null : trim($text);

        return $text === null || $text === '' ? null : $text;
    }

    /** Every department with what each of its roles may do, for the owner's roles page. @return array<int, array<string, mixed>> */
    public function permissionOverview(): array
    {
        return Department::query()->orderBy('sort_order')->orderBy('id')->get()->map(fn (Department $d) => [
            'id' => $d->id,
            'name' => $d->name,
            'code' => $d->code,
            'status' => $d->status->value,
            'permissions' => $this->permissionMatrix($d),
        ])->values()->all();
    }

    /** @return array<string, string[]> */
    private function permissionMatrix(Department $d): array
    {
        $matrix = [];
        foreach (DepartmentRole::cases() as $role) {
            $matrix[$role->value] = [];
        }
        foreach (DepartmentRolePermission::query()->where('department_id', $d->id)->get() as $row) {
            $matrix[$row->role->value][] = $row->permission->value;
        }

        return $matrix;
    }

    /**
     * The counts of one department. The directory passes `$counts` (read for all departments in grouped queries) so
     * that it does not query per department; a single panel reads its own.
     *
     * @param array{members: \Illuminate\Support\Collection, resources: \Illuminate\Support\Collection, pending: \Illuminate\Support\Collection}|null $counts
     */
    private function summary(Department $d, CarbonImmutable $now, ?array $counts = null): array
    {
        $resources = $counts !== null ? ($counts['resources']->get($d->id) ?? collect()) : $d->resources()->get();

        return [
            'id' => $d->id,
            'name' => $d->name,
            'code' => $d->code,
            'description' => $d->description,
            'status' => $d->status->value,
            'sortOrder' => $d->sort_order,
            'memberCount' => $counts !== null ? (int) ($counts['members']->get($d->id) ?? 0) : $d->members()->count(),
            'resourceCount' => $resources->count(),
            'staleResourceCount' => $resources->filter(fn (WorkResource $r) => $this->isStale($r, $now))->count(),
            'pendingSuggestions' => $counts !== null
                ? (int) ($counts['pending']->get($d->id) ?? 0)
                : $d->suggestions()->where('status', SuggestionStatus::PENDING->value)->count(),
        ];
    }

    private function resourceView(WorkResource $r, CarbonImmutable $now): array
    {
        return [
            'id' => $r->id,
            'name' => $r->name,
            'resourceType' => $r->resource_type,
            'capacity' => (int) $r->capacity,
            'status' => $r->status,
            'description' => $r->description,
            'reviewedAt' => self::iso($r->reviewed_at),
            'stale' => $this->isStale($r, $now),
        ];
    }

    private function suggestionView(DepartmentSuggestion $s): array
    {
        return [
            'id' => $s->id,
            'departmentId' => $s->department_id,
            'departmentName' => $s->department?->name,
            'title' => $s->title,
            'body' => $s->body,
            'status' => $s->status->value,
            'resourceId' => $s->resource_id,
            'resourceName' => $s->resource?->name,
            'authorId' => $s->author_id,
            'authorName' => $s->author?->full_name,
            'createdAt' => self::iso($s->created_at),
            'decidedAt' => self::iso($s->decided_at),
            'decidedBy' => $s->decided_by,
            'managerNote' => $s->manager_note,
        ];
    }

    private static function iso(?\DateTimeInterface $t): ?string
    {
        return $t === null ? null : CarbonImmutable::instance($t)->utc()->format('Y-m-d\TH:i:s.v\Z');
    }

    private static function describeResource(WorkResource $r): string
    {
        return "{$r->name} / capacity {$r->capacity} / {$r->status}";
    }

    /** @param Collection<int, DepartmentMember> $members */
    private function memberText(Collection $members): string
    {
        return $members->map(fn (DepartmentMember $m) => "{$m->user_id}:{$m->role->value}")->implode(', ');
    }

    /** @param array<string, string[]> $matrix */
    private function matrixText(array $matrix): string
    {
        return implode('; ', array_map(
            static fn (string $role, array $perms) => $role . '=' . implode(',', $perms),
            array_keys($matrix),
            array_values($matrix),
        ));
    }
}
