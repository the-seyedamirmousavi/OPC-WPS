<?php

namespace App\Models;

use App\Enums\Capability;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A role of the company: its name, whether its people receive tasks, and the capabilities it holds. The key is the value
 * stored on each user (app_user.user_role). OWNER and AGENT are locked and are never edited or deleted.
 *
 * @property string $key
 * @property string $name
 * @property string|null $description
 * @property bool $receives_tasks
 * @property string[] $capabilities
 * @property bool $builtin
 * @property bool $locked
 */
class AppRole extends Model
{
    public const OWNER = 'OWNER';
    public const MANAGER = 'MANAGER';
    public const USER_1 = 'USER_1';
    public const USER_2 = 'USER_2';
    public const USER_3 = 'USER_3';
    public const AGENT = 'AGENT';

    protected $table = 'app_role';
    protected $primaryKey = 'key';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = ['key', 'name', 'description', 'receives_tasks', 'capabilities', 'builtin', 'locked', 'sort_order', 'created_at'];

    protected function casts(): array
    {
        return ['receives_tasks' => 'boolean', 'capabilities' => 'array', 'builtin' => 'boolean', 'locked' => 'boolean', 'created_at' => 'datetime'];
    }

    /** Whether the role holds a capability. The owner holds all of them; a locked agent holds none. */
    public function has(string $capability): bool
    {
        if ($this->key === self::OWNER) {
            return true;
        }
        if ($capability === Capability::TASKS_RECEIVE->value) {
            return (bool) $this->receives_tasks;
        }
        if ($this->locked) {
            return false;
        }

        return in_array($capability, $this->capabilities ?? [], true);
    }

    /** Whether the role gives out management: only the owner may assign it. */
    public function isManagerial(): bool
    {
        return array_intersect(Capability::managerial(), $this->capabilities ?? []) !== [];
    }

    /** Whether people may be given this role (the owner and the agents are not). */
    public function isAssignable(): bool
    {
        return !$this->locked;
    }

    public function users(): HasMany
    {
        return $this->hasMany(AppUser::class, 'user_role', 'key');
    }

    /** The keys of the roles that manage others: only the owner may give them out or change their holders. @return string[] */
    public static function managerialKeys(): array
    {
        return self::query()->get()->filter(fn (self $r) => $r->isManagerial())->pluck('key')->values()->all();
    }

    /** The keys of the roles that hold a capability (the owner always does). @return string[] */
    public static function keysWith(string $capability): array
    {
        return self::query()->get()->filter(fn (self $r) => $r->has($capability))->pluck('key')->values()->all();
    }
}
