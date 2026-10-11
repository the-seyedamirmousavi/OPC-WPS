<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Manager FORCE on one operation: a fixed start and/or a fixed executor. At most one per operation. */
class ScheduleForce extends Model
{
    protected $table = 'schedule_force';
    protected $primaryKey = 'operation_id';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;
    protected $fillable = ['operation_id', 'forced_start', 'forced_user_id', 'reason', 'created_by', 'created_at'];

    protected function casts(): array
    {
        return ['forced_start' => 'datetime', 'created_at' => 'datetime'];
    }
}
