<?php

namespace App\AI\Departments;

use App\Enums\DepartmentRole;
use App\Models\AppRole;
use App\Exceptions\ApiException;
use App\Models\AppUser;
use App\Models\Department;
use App\Models\DepartmentMember;
use App\Services\DepartmentService;
use App\Services\UserService;
use Illuminate\Support\Facades\DB;

/**
 * Human resources: the system's user accounts. Owner only. Only id, name, role and whether the account is active are
 * returned; contact details, messenger ids and anything about passwords never leave the database.
 */
class HrHandler extends BaseHandler
{
    protected function capability(): ?string
    {
        return 'users.manage';
    }

    public function __construct(private readonly UserService $users, private readonly DepartmentService $departments)
    {
    }

    /** Accounts and department membership, through the same services as the screens. */
    protected function run(string $operation, array $params, AppUser $user, Department $department): array
    {
        $agent = $this->agentOperation($operation, $params, $user);
        if ($agent !== null) {
            return $agent;
        }

        return DB::transaction(fn () => match ($operation) {
            'set_user_active' => $this->setActive($params, $user),
            'set_user_role' => $this->setRole($params, $user),
            'add_department_member' => $this->changeMembers($params, $user, true),
            'remove_department_member' => $this->changeMembers($params, $user, false),
            default => throw ApiException::badRequest("Operation {$operation} is not available here"),
        });
    }

    private function setActive(array $params, AppUser $user): array
    {
        $u = $this->users->update($params['user_id'], null, null, (bool) $params['active'], null, null, $user);

        return ['user_id' => $u['id'], 'active' => $u['active']];
    }

    private function setRole(array $params, AppUser $user): array
    {
        $u = $this->users->update($params['user_id'], null, $params['role'], null, null, null, $user);

        return ['user_id' => $u['id'], 'role' => $u['role']];
    }

    /** The members of the department are replaced as a whole by the service, so the list is read, changed and sent. */
    private function changeMembers(array $params, AppUser $user, bool $add): array
    {
        $current = DepartmentMember::query()->where('department_id', $params['department_id'])->get()
            ->map(fn (DepartmentMember $m) => ['userId' => $m->user_id, 'role' => $m->role])->all();
        $members = array_values(array_filter($current, fn (array $m) => $m['userId'] !== $params['user_id']));
        if ($add) {
            $members[] = ['userId' => $params['user_id'], 'role' => DepartmentRole::from($params['role'])];
        }
        $this->departments->setMembers($params['department_id'], $members, $user);

        return ['department_id' => $params['department_id'], 'user_id' => $params['user_id'], 'members' => count($members)];
    }

    /** One account with its department memberships. No contact details, no password, no messenger id. */
    private function userDetail(string $userId): array
    {
        $u = AppUser::query()->find($userId);
        if ($u === null) {
            return ['found' => false];
        }

        return [
            'found' => true, 'id' => $u->id, 'name' => $u->full_name, 'role' => $u->user_role, 'active' => (bool) $u->active,
            'departments' => DepartmentMember::query()->with('department:id,name')->where('user_id', $userId)->get()
                ->map(fn (DepartmentMember $m) => ['name' => $m->department?->name, 'role' => $m->role->value ?? (string) $m->role])
                ->values()->all(),
        ];
    }

    /** The people of one department with their role there. */
    private function departmentMembers(int $departmentId, int $limit): array
    {
        $d = Department::query()->find($departmentId);
        if ($d === null) {
            return ['data' => ['found' => false], 'truncated' => false];
        }
        $rows = DepartmentMember::query()->with('user:id,full_name')->where('department_id', $departmentId)->orderBy('id')
            ->limit($limit + 1)->get()
            ->map(fn (DepartmentMember $m) => ['id' => $m->user_id, 'name' => $m->user?->full_name, 'role' => $m->role->value ?? (string) $m->role]);
        $cut = self::cut($rows, $limit);

        return ['data' => ['found' => true, 'department' => $d->name] + ['members' => $cut['data']], 'truncated' => $cut['truncated']];
    }

    protected function read(string $intent, array $params, AppUser $user, Department $department): array
    {
        if ($intent === 'user_detail') {
            return ['data' => $this->userDetail((string) $params['user_id'])];
        }
        if ($intent === 'department_members') {
            return $this->departmentMembers((int) $params['department_id'], self::limit($params));
        }
        if ($intent === 'users_summary') {
            $byRole = AppUser::query()->selectRaw('user_role, count(*) as n')->groupBy('user_role')->pluck('n', 'user_role')->all();

            return ['data' => [
                'by_role' => $byRole,
                'total' => array_sum($byRole),
                'active' => AppUser::query()->where('active', true)->count(),
            ]];
        }

        $limit = self::limit($params);
        if ($intent === 'search_users') {
            $candidates = AppUser::query()->limit(500)->get(['id', 'full_name', 'user_role', 'active'])->map(fn (AppUser $u) => [
                'id' => $u->id, 'text' => "{$u->id} {$u->full_name}",
                'row' => ['id' => $u->id, 'name' => $u->full_name, 'role' => $u->user_role, 'active' => (bool) $u->active],
            ])->all();

            return self::search((string) $params['query'], $candidates, $limit);
        }
        $q = AppUser::query()->orderBy('id')->limit($limit + 1);
        if (!empty($params['role'])) {
            $q->where('user_role', $params['role']);
        }

        return self::cut(
            $q->get(['id', 'full_name', 'user_role', 'active'])->map(fn (AppUser $u) => [
                'id' => $u->id, 'name' => $u->full_name, 'role' => $u->user_role, 'active' => (bool) $u->active,
            ]),
            $limit,
        );
    }
}
