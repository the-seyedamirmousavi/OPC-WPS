<?php

namespace App\Models;

use App\Enums\DepartmentRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A user's place in a department and the role they hold there. */
class DepartmentMember extends Model
{
    protected $table = 'department_member';
    public $timestamps = false;
    protected $fillable = ['department_id', 'user_id', 'role'];

    protected function casts(): array
    {
        return ['role' => DepartmentRole::class];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(AppUser::class, 'user_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }
}
