<?php

namespace App\Models;

use App\Enums\Role;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * An application user. The business id (e.g. "owner", "user1") is the primary key, as in the original schema.
 *
 * @property string $id
 * @property string $full_name
 * @property Role $user_role
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
        'must_change_password', 'created_at',
    ];

    protected $hidden = ['password_hash', 'remember_token'];

    protected function casts(): array
    {
        return [
            'user_role' => Role::class,
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

    public function role(): Role
    {
        return $this->user_role;
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
