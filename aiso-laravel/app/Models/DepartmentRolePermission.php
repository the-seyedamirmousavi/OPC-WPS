<?php

namespace App\Models;

use App\Enums\DepartmentPermission;
use App\Enums\DepartmentRole;
use Illuminate\Database\Eloquent\Model;

/** One permission held by a department role. */
class DepartmentRolePermission extends Model
{
    protected $table = 'department_role_permission';
    public $timestamps = false;
    protected $fillable = ['department_id', 'role', 'permission'];

    protected function casts(): array
    {
        return ['role' => DepartmentRole::class, 'permission' => DepartmentPermission::class];
    }
}
