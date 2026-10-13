<?php

namespace App\Models;

use App\Enums\DepartmentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property ?string $description
 * @property DepartmentStatus $status
 * @property int $sort_order
 * @property ?string $code system code of a department the application knows (HR = human resources)
 */
class Department extends Model
{
    protected $table = 'department';
    public $timestamps = false;
    protected $fillable = ['name', 'description', 'status', 'sort_order', 'created_at', 'code'];

    /** The human resources department, which holds the user management. */
    public const HR = 'HR';

    /** The production department: its panel keeps the import history. */
    public const PRODUCTION = 'PRODUCTION';

    protected function casts(): array
    {
        return ['status' => DepartmentStatus::class, 'sort_order' => 'integer', 'created_at' => 'datetime'];
    }

    public function isHr(): bool
    {
        return $this->code === self::HR;
    }

    public function isProduction(): bool
    {
        return $this->code === self::PRODUCTION;
    }

    public function isActive(): bool
    {
        return $this->status === DepartmentStatus::ACTIVE;
    }

    public function members(): HasMany
    {
        return $this->hasMany(DepartmentMember::class, 'department_id');
    }

    public function resources(): HasMany
    {
        return $this->hasMany(WorkResource::class, 'department_id');
    }

    public function suggestions(): HasMany
    {
        return $this->hasMany(DepartmentSuggestion::class, 'department_id');
    }
}
