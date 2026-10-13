<?php

namespace App\Services;

use App\Enums\OperationStatus;
use App\Exceptions\ApiException;
use App\Models\AppRole;
use App\Models\AppUser;
use App\Models\Operation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/** User accounts and roles (human resources). Passwords are stored as bcrypt hashes. */
class UserService
{
    public function __construct(private readonly AuditService $audit, private readonly LicenseService $license)
    {
    }

    /** @return array[] */
    public function list(): array
    {
        $users = AppUser::query()->with('role')->get()->all();
        $order = array_flip(AppRole::query()->orderBy('sort_order')->orderBy('key')->pluck('key')->all());
        usort($users, static fn (AppUser $a, AppUser $b): int => ($order[$a->user_role] ?? PHP_INT_MAX) <=> ($order[$b->user_role] ?? PHP_INT_MAX)
            ?: strcmp($a->id, $b->id));

        return array_map([self::class, 'view'], $users);
    }

    /** Without an id, the next free one is generated (u001, u002, …). */
    public function create(?string $id, string $fullName, string $role, ?string $messengerId, ?string $contact,
                           string $password, AppUser $actor): array
    {
        if ($role === AppRole::OWNER) {
            throw ApiException::badRequest('The OWNER account cannot be created here');
        }
        RoleService::assignable($role);
        if (!$actor->isOwner() && in_array($role, AppRole::managerialKeys(), true)) {
            throw ApiException::forbidden('Only the owner can give a role that manages others');
        }
        $id = $id === null || trim($id) === '' ? self::nextId() : trim($id);
        if (AppUser::query()->whereKey($id)->exists()) {
            throw ApiException::conflict("User id '{$id}' already exists");
        }
        self::checkPassword($password);
        $this->license->assertCanAddUsers();

        return DB::transaction(function () use ($id, $fullName, $role, $messengerId, $contact, $password, $actor) {
            $u = AppUser::query()->create([
                'id' => trim($id),
                'full_name' => trim($fullName),
                'user_role' => $role,
                'messenger_id' => $messengerId,
                'active' => true,
                'contact_info' => $contact,
                'password_hash' => Hash::make($password),
                'must_change_password' => true,
                'created_at' => CarbonImmutable::now(),
            ]);
            $this->audit->record($actor->id, 'USER_CREATED', 'User', $u->id, null, $role, null);

            return self::view($u->load('role'));
        });
    }

    public function update(string $id, ?string $fullName, ?string $role, ?bool $active, ?string $messengerId,
                           ?string $contact, AppUser $actor): array
    {
        return DB::transaction(function () use ($id, $fullName, $role, $active, $messengerId, $contact, $actor) {
            $u = self::find($id);
            if ($u->isAgent()) {
                throw ApiException::forbidden('An AI agent is managed in the Agents tab of human resources');
            }
            if ($u->isOwner() && (($role !== null && $role !== AppRole::OWNER) || $active === false)) {
                throw ApiException::forbidden('The OWNER account cannot be demoted or disabled');
            }
            if ($role === AppRole::OWNER && !$u->isOwner()) {
                throw ApiException::forbidden('Ownership cannot be granted through the API');
            }
            if ($role !== null) {
                RoleService::assignable($role);
            }
            // the roles that manage others (production, accounts, departments) are the owner's to give and to change
            $managerial = AppRole::managerialKeys();
            if (!$actor->isOwner() && (in_array($u->user_role, $managerial, true) || ($role !== null && in_array($role, $managerial, true)))) {
                throw ApiException::forbidden('Only the owner can change an account that manages others, or give such a role');
            }
            $before = $u->user_role . '/' . ($u->active ? 'true' : 'false');
            if ($fullName !== null && trim($fullName) !== '') {
                $u->full_name = trim($fullName);
            }
            if ($role !== null) {
                $u->user_role = $role;
            }
            if ($messengerId !== null) {
                $u->messenger_id = $messengerId;
            }
            if ($contact !== null) {
                $u->contact_info = $contact;
            }
            if ($active !== null) {
                if (!$active && $u->active) {
                    $open = Operation::query()->where('assigned_user_id', $id)->get()
                        ->filter(fn (Operation $o) => $o->status->isActive() || $o->status === OperationStatus::BLOCKED)
                        ->count();
                    if ($open > 0) {
                        throw ApiException::conflict("User {$id} still has {$open} open task(s). Reassign or unassign them first.");
                    }
                }
                if ($active && !$u->active) {
                    $this->license->assertCanAddUsers();
                }
                $u->active = $active;
            }
            $u->save();
            $this->audit->record($actor->id, 'USER_UPDATED', 'User', $id, $before,
                $u->user_role . '/' . ($u->active ? 'true' : 'false'), null);

            return self::view($u->load('role'));
        });
    }

    /**
     * Deletes an account for good, together with its personal data (memberships, notifications, private chats and assistant
     * history). An account that still has work history (operations, forces, reports, suggestions, agents) is kept: it
     * has to be deactivated instead, so the record of who did what stays intact.
     */
    public function delete(string $id, AppUser $actor): void
    {
        $u = self::find($id);
        if ($u->isAgent()) {
            throw ApiException::forbidden('An AI agent is managed in the Agents tab of human resources');
        }
        if ($u->isOwner()) {
            throw ApiException::forbidden('The OWNER account cannot be deleted');
        }
        if ($id === $actor->id) {
            throw ApiException::forbidden('You cannot delete your own account');
        }
        $open = Operation::query()->where('assigned_user_id', $id)->get()
            ->filter(fn (Operation $o) => $o->status->isActive() || $o->status === OperationStatus::BLOCKED)
            ->count();
        if ($open > 0) {
            throw ApiException::conflict("User {$id} still has {$open} open task(s). Reassign or unassign them first.");
        }
        $history = self::workHistory($id);
        if ($history > 0) {
            throw ApiException::conflict("User {$id} has work history ({$history} record(s)) and cannot be deleted. Deactivate it instead.");
        }

        $before = $u->user_role . '/' . ($u->active ? 'true' : 'false');
        DB::transaction(function () use ($id, $u, $before, $actor) {
            // personal data goes first; the assistant's runs take their steps and pending actions with them
            DB::table('department_member')->where('user_id', $id)->delete();
            DB::table('notification')->where('recipient_id', $id)->delete();
            DB::table('chat_conversation')->where('user_id', $id)->delete();
            DB::table('ai_pending_actions')->where('user_id', $id)->delete();
            DB::table('ai_runs')->where('user_id', $id)->delete();
            DB::table('sessions')->where('user_id', $id)->delete();
            $u->delete();
            $this->audit->record($actor->id, 'USER_DELETED', 'User', $id, $before, null, null);
        });
    }

    public function resetPassword(string $id, string $newPassword, AppUser $actor): void
    {
        $u = self::find($id);
        if ($u->isAgent()) {
            throw ApiException::forbidden('An AI agent has no password');
        }
        if ($u->isOwner() && $actor->id !== $id) {
            throw ApiException::forbidden("Only the owner can reset the owner's password");
        }
        self::checkPassword($newPassword);
        $u->password_hash = Hash::make($newPassword);
        $u->must_change_password = true;
        $u->save();
        $this->audit->record($actor->id, 'PASSWORD_RESET', 'User', $id, null, null, null);
    }

    public function changeOwnPassword(AppUser $me, string $current, string $next): void
    {
        $u = self::find($me->id);
        if (!Hash::check($current, $u->password_hash)) {
            throw ApiException::badRequest('Current password is incorrect');
        }
        self::checkPassword($next);
        $u->password_hash = Hash::make($next);
        $u->must_change_password = false;
        $u->save();
        $this->audit->record($me->id, 'PASSWORD_CHANGED', 'User', $me->id, null, null, null);
    }

    /** How many records of work refer to this account. Each one is a reason to keep the account. */
    private static function workHistory(string $id): int
    {
        $references = [
            ['operation', 'responsible_user_id'], ['operation', 'assigned_user_id'], ['schedule_force', 'forced_user_id'],
            ['operation_report', 'user_id'], ['assignment_proposal', 'user_id'], ['department_suggestion', 'author_id'],
            ['department_suggestion', 'decided_by'], ['agent_tasks', 'created_by'], ['app_user', 'agent_created_by'],
        ];
        $count = 0;
        foreach ($references as [$table, $column]) {
            $count += DB::table($table)->where($column, $id)->count();
        }

        return $count;
    }

    /** The next free id for an account created without one: u001, u002, … */
    private static function nextId(): string
    {
        $max = 0;
        foreach (AppUser::query()->where('id', 'like', 'u%')->pluck('id') as $id) {
            if (preg_match('/^u(\d{3,})$/', (string) $id, $m) === 1) {
                $max = max($max, (int) $m[1]);
            }
        }

        return sprintf('u%03d', $max + 1);
    }

    private static function find(string $id): AppUser
    {
        $u = AppUser::query()->find($id);
        if ($u === null) {
            throw ApiException::notFound("User {$id} not found");
        }

        return $u;
    }

    private static function checkPassword(?string $p): void
    {
        if ($p === null || mb_strlen($p) < 8) {
            throw ApiException::badRequest('Password must be at least 8 characters');
        }
    }

    public static function view(AppUser $u): array
    {
        return [
            'id' => $u->id,
            'fullName' => $u->full_name,
            'role' => $u->user_role,
            'roleName' => $u->role?->name ?? $u->user_role,
            'capabilities' => $u->capabilities(),
            'receivesTasks' => $u->receivesTasks(),
            'messengerId' => $u->messenger_id,
            'active' => (bool) $u->active,
            'contactInfo' => $u->contact_info,
            'mustChangePassword' => (bool) $u->must_change_password,
            'isAgent' => $u->isAgent(),
            'createdAt' => $u->created_at?->utc()->format('Y-m-d\TH:i:s.v\Z'),
        ];
    }
}
