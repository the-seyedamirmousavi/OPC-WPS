<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkResource extends Model
{
    protected $table = 'work_resource';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;
    protected $fillable = [
        'id', 'name', 'resource_type', 'responsible_user_id', 'capacity', 'status', 'description', 'sort_order',
        'department_id', 'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
            'sort_order' => 'integer',
            'department_id' => 'integer',
            'reviewed_at' => 'datetime',
        ];
    }

    /** Resources in the order they were entered (ties and older rows by id). */
    public static function inEnteredOrder(): \Illuminate\Database\Eloquent\Builder
    {
        return self::query()->orderBy('sort_order')->orderBy('id');
    }

    /** The department that keeps this resource up to date, if one was assigned. */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    public function isActive(): bool
    {
        return $this->status === 'ACTIVE';
    }
}
