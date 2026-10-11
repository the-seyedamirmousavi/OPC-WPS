<?php

namespace App\Services;

use App\Enums\OperationStatus;
use App\Enums\Role;
use App\Exceptions\ApiException;
use App\Models\AppUser;
use App\Models\Operation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/** User accounts and roles (owner console). Passwords are stored as bcrypt hashes. */
class UserService
{
    public function __construct(private readonly AuditService $audit)
    {
    }

    /** @return array[] */
    public function list(): array
    {
        $users = AppUser::query()->get()->all();
        usort($users, static fn (AppUser $a, AppUser $b): int => self::roleOrder($a->user_role) <=> self::roleOrder($b->user_role)
            ?: strcmp($a->id, $b->id));

        return array_map([self::class, 'view'], $users);
    }

    public function create(string $id, string $fullName, Role $role, ?string $messengerId, ?string $contact,
                           string $password, AppUser $actor): array
    {
        if ($role === Role::OWNER) {
            throw ApiException::badRequest('The OWNER account cannot be created here');
        }
        if (AppUser::query()->whereKey($id)->exists()) {
            throw ApiException::conflict("User id '{$id}' already exists");
        }
        self::checkPassword($password);

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

            return self::view($u);
        });
    }

    public function update(string $id, ?string $fullName, ?Role $role, ?bool $active, ?string $messengerId,
                           ?string $contact, AppUser $actor): array
    {
        return DB::transaction(function () use ($id, $fullName, $role, $active, $messengerId, $contact, $actor) {
            $u = self::find($id);
            if ($u->user_role === Role::OWNER && (($role !== null && $role !== Role::OWNER) || $active === false)) {
                throw ApiException::forbidden('The OWNER account cannot be demoted or disabled');
            }
            if ($role === Role::OWNER && $u->user_role !== Role::OWNER) {
                throw ApiException::forbidden('Ownership cannot be granted through the API');
            }
            $before = $u->user_role->value . '/' . ($u->active ? 'true' : 'false');
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
                $u->active = $active;
            }
            $u->save();
            $this->audit->record($actor->id, 'USER_UPDATED', 'User', $id, $before,
                $u->user_role->value . '/' . ($u->active ? 'true' : 'false'), null);

            return self::view($u);
        });
    }

    public function resetPassword(string $id, string $newPassword, AppUser $actor): void
    {
        $u = self::find($id);
        if ($u->user_role === Role::OWNER && $actor->id !== $id) {
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

    /** Role order as declared in the enum: OWNER, MANAGER, USER_1..3. */
    private static function roleOrder(Role $r): int
    {
        return array_search($r, Role::cases(), true);
    }

    public static function view(AppUser $u): array
    {
        return [
            'id' => $u->id,
            'fullName' => $u->full_name,
            'role' => $u->user_role->value,
            'messengerId' => $u->messenger_id,
            'active' => (bool) $u->active,
            'contactInfo' => $u->contact_info,
            'mustChangePassword' => (bool) $u->must_change_password,
            'createdAt' => $u->created_at?->utc()->format('Y-m-d\TH:i:s.v\Z'),
        ];
    }
}
