<?php

namespace App\Services;

use App\Enums\Capability;
use App\Exceptions\ApiException;
use App\Models\AppRole;
use App\Models\AppUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** The company's roles: defined by the owner, given to people in human resources. */
class RoleService
{
    public function __construct(private readonly AuditService $audit)
    {
    }

    /** @return array<int, array<string, mixed>> every role, with how many people hold it */
    public function list(): array
    {
        $holders = AppUser::query()->get(['user_role', 'active'])->groupBy('user_role');

        return AppRole::query()->orderBy('sort_order')->orderBy('key')->get()
            ->map(fn (AppRole $r) => self::view($r, $holders->get($r->key, collect())))->values()->all();
    }

    /** The roles a person can be given, in order. @return string[] */
    public static function assignableKeys(): array
    {
        return AppRole::query()->where('locked', false)->orderBy('sort_order')->orderBy('key')->pluck('key')->values()->all();
    }

    /** The role a person may be given, or a clear refusal. */
    public static function assignable(string $key): AppRole
    {
        $role = AppRole::query()->find($key);
        if ($role === null || !$role->isAssignable()) {
            throw ApiException::badRequest("The role '{$key}' cannot be given to a person");
        }

        return $role;
    }

    public function create(string $name, ?string $description, bool $receivesTasks, array $capabilities, AppUser $actor): array
    {
        $name = trim($name);
        if ($name === '') {
            throw ApiException::badRequest('A role needs a name');
        }
        $capabilities = self::checkCapabilities($capabilities);
        $key = self::newKey();

        return DB::transaction(function () use ($key, $name, $description, $receivesTasks, $capabilities, $actor) {
            $role = AppRole::query()->create([
                'key' => $key, 'name' => $name, 'description' => self::text($description), 'receives_tasks' => $receivesTasks,
                'capabilities' => $capabilities, 'builtin' => false, 'locked' => false,
                'sort_order' => (int) AppRole::query()->max('sort_order') + 1, 'created_at' => now(),
            ]);
            $this->audit->record($actor->id, 'ROLE_CREATED', 'Role', $key, null, self::summary($role), null);

            return self::view($role, collect());
        });
    }

    public function update(string $key, ?string $name, ?string $description, ?bool $receivesTasks, ?array $capabilities, AppUser $actor): array
    {
        $role = self::find($key);
        if ($role->locked) {
            throw ApiException::forbidden('The owner and the AI agent roles cannot be changed');
        }
        $before = self::summary($role);
        if ($name !== null) {
            if (trim($name) === '') {
                throw ApiException::badRequest('A role needs a name');
            }
            $role->name = trim($name);
        }
        if ($description !== null) {
            $role->description = self::text($description);
        }
        if ($receivesTasks !== null) {
            $role->receives_tasks = $receivesTasks;
        }
        if ($capabilities !== null) {
            $role->capabilities = self::checkCapabilities($capabilities);
        }
        $role->save();
        $this->audit->record($actor->id, 'ROLE_UPDATED', 'Role', $key, $before, self::summary($role), null);

        return self::view($role, AppUser::query()->where('user_role', $key)->get(['user_role', 'active']));
    }

    public function delete(string $key, AppUser $actor): void
    {
        $role = self::find($key);
        if ($role->builtin || $role->locked) {
            throw ApiException::forbidden('A built-in role cannot be deleted');
        }
        $holders = AppUser::query()->where('user_role', $key)->count();
        if ($holders > 0) {
            throw ApiException::conflict("The role {$role->name} is held by {$holders} user(s). Give them another role first.");
        }
        $before = self::summary($role);
        $role->delete();
        $this->audit->record($actor->id, 'ROLE_DELETED', 'Role', $key, $before, null, null);
    }

    /** @return array<string, mixed> */
    public static function view(AppRole $r, $holders): array
    {
        return [
            'key' => $r->key,
            'name' => $r->name,
            'description' => $r->description,
            'receivesTasks' => (bool) $r->receives_tasks,
            'capabilities' => $r->capabilities ?? [],
            'builtin' => (bool) $r->builtin,
            'locked' => (bool) $r->locked,
            'assignable' => $r->isAssignable(),
            'total' => $holders->count(),
            'active' => $holders->filter(fn ($u) => (bool) $u->active)->count(),
        ];
    }

    private static function find(string $key): AppRole
    {
        $role = AppRole::query()->find($key);
        if ($role === null) {
            throw ApiException::notFound("Role {$key} not found");
        }

        return $role;
    }

    /** @return string[] the capabilities, all of them known */
    private static function checkCapabilities(array $capabilities): array
    {
        $capabilities = array_values(array_unique(array_map('strval', $capabilities)));
        $unknown = array_diff($capabilities, Capability::grantable());
        if ($unknown !== []) {
            throw ApiException::badRequest('Unknown capability: ' . implode(', ', $unknown));
        }

        return $capabilities;
    }

    private static function newKey(): string
    {
        do {
            $key = 'ROLE-' . strtoupper(Str::random(6));
        } while (AppRole::query()->whereKey($key)->exists());

        return $key;
    }

    private static function text(?string $s): ?string
    {
        $s = $s === null ? null : trim($s);

        return $s === '' ? null : $s;
    }

    private static function summary(AppRole $r): string
    {
        return $r->name . ' [' . ($r->receives_tasks ? 'tasks' : 'no tasks') . '] ' . implode(',', $r->capabilities ?? []);
    }
}
