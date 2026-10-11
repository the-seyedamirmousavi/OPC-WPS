<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Append-only audit trail. Written only by App\Services\AuditService. */
class AuditEvent extends Model
{
    protected $table = 'audit_event';
    protected $primaryKey = 'event_id';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;
    protected $fillable = [
        'event_id', 'occurred_at', 'actor_id', 'action_name', 'entity_type', 'entity_id', 'previous_value',
        'new_value', 'reason', 'project_id',
    ];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }
}
