<?php

namespace App\Models;

use App\Enums\Capability;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * An application user. The business id (e.g. "owner", "user1") is the primary key, as in the original schema. The role is
 * a row of app_role; what a user may do follows from that role's capabilities.
 *
 * @property string $id
 * @property string $full_name
 * @property string $user_role the key of the user's role (app_role.key)
 * @property bool $active
 * @property string $password_hash
 * @property bool $must_change_password
 */
class AppUser extends Authenticatable
{
    protected $table = 'app_user';
    protected $primaryKey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = [
        'id', 'full_name', 'user_role', 'messenger_id', 'active', 'contact_info', 'password_hash',
        'must_change_password', 'created_at', 'agent_instructions', 'agent_created_by', 'agent_scope',
    ];

    /** An AI agent: a virtual user that cannot sign in, receives no operations and takes no licence seat. */
    public function isAgent(): bool
    {
        return $this->user_role === AppRole::AGENT;
    }

    /** The owner: an identity, not a grant. */
    public function isOwner(): bool
    {
        return $this->user_role === AppRole::OWNER;
    }

    /** The people: everyone who is not an agent. */
    public function scopePeople(Builder $q): Builder
    {
        return $q->where('user_role', '!=', AppRole::AGENT);
    }

    /** @return string[] the department slugs an agent may read */
    public function agentScope(): array
    {
        $scope = json_decode((string) $this->agent_scope, true);

        return is_array($scope) ? array_values(array_filter($scope, 'is_string')) : [];
    }

    /** The user's role (null only if the role was removed, which the API does not allow). */
    public function role(): BelongsTo
    {
        return $this->belongsTo(AppRole::class, 'user_role', 'key');
    }

    /** Whether the user holds a capability through the role. The owner holds all of them. */
    public function hasCapability(string $capability): bool
    {
        if ($this->isOwner()) {
            return true;
        }

        return $this->role?->has($capability) ?? false;
    }

    /**
     * Whether the user passes a requirement: a capability name, or null for "any person" (an agent passes nothing that
     * names a capability, and nothing that asks for a person).
     */
    public function allows(?string $capability): bool
    {
        return $capability === null ? !$this->isAgent() : $this->hasCapability($capability);
    }

    /** Whether operations are assigned to this user (the role's receives_tasks flag). */
    public function receivesTasks(): bool
    {
        return $this->isOwner() ? false : ($this->role?->has(Capability::TASKS_RECEIVE->value) ?? false);
    }

    /** Every capability the user holds, for the page to show what it may do. @return string[] */
    public function capabilities(): array
    {
        if ($this->isOwner()) {
            return Capability::grantable();
        }
        $held = $this->role?->capabilities ?? [];

        return $this->receivesTasks() ? [...$held, Capability::TASKS_RECEIVE->value] : $held;
    }

    protected $hidden = ['password_hash', 'remember_token'];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'must_change_password' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function getAuthPasswordName(): string
    {
        return 'password_hash';
    }

    public function getAuthPassword(): string
    {
        return (string) $this->password_hash;
    }

    public function isActive(): bool
    {
        return (bool) $this->active;
    }

    public function operations(): HasMany
    {
        return $this->hasMany(Operation::class, 'assigned_user_id');
    }
}
