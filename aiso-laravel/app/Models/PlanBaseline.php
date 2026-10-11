<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Reference plan entry: planned start/end of an unfinished operation at the time it was saved. */
class PlanBaseline extends Model
{
    protected $table = 'plan_baseline';
    protected $primaryKey = 'operation_id';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;
    protected $fillable = ['operation_id', 'planned_start', 'planned_end', 'saved_at'];

    protected function casts(): array
    {
        return ['planned_start' => 'datetime', 'planned_end' => 'datetime', 'saved_at' => 'datetime'];
    }
}
