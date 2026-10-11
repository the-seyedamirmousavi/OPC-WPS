<?php

namespace App\Models;

use App\Enums\OperationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Operation extends Model
{
    protected $table = 'operation';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = [
        'id', 'name', 'project_id', 'item_id', 'resource_id', 'responsible_user_id', 'preparation_time',
        'transport_time', 'setup_time', 'direct_time', 'status', 'assigned_user_id', 'block_reason',
        'status_before_block', 'progress_percent', 'planned_start', 'planned_end', 'assigned_at', 'started_at',
        'completed_at', 'completion_approved', 'completion_approved_by', 'cancel_reason', 'description',
        'created_at', 'updated_at', 'version',
    ];

    protected function casts(): array
    {
        return [
            'preparation_time' => 'float',
            'transport_time' => 'float',
            'setup_time' => 'float',
            'direct_time' => 'float',
            'status' => OperationStatus::class,
            'status_before_block' => OperationStatus::class,
            'progress_percent' => 'integer',
            'planned_start' => 'datetime',
            'planned_end' => 'datetime',
            'assigned_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'completion_approved' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'version' => 'integer',
        ];
    }

    /** Total duration in hours (preparation + transport + setup + direct). */
    public function totalHours(): float
    {
        return (float) $this->preparation_time + (float) $this->transport_time
            + (float) $this->setup_time + (float) $this->direct_time;
    }

    public function predecessors(): HasMany
    {
        return $this->hasMany(Predecessor::class, 'operation_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function resource(): BelongsTo
    {
        return $this->belongsTo(WorkResource::class, 'resource_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(AppUser::class, 'assigned_user_id');
    }

    public function responsibleUser(): BelongsTo
    {
        return $this->belongsTo(AppUser::class, 'responsible_user_id');
    }

    public function force(): HasOne
    {
        return $this->hasOne(ScheduleForce::class, 'operation_id');
    }
}
