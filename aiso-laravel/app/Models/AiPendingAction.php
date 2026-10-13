<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An action the AI proposed and the backend validated. It runs only after the same user confirms it, before it expires.
 *
 * @property int $id
 * @property string $user_id
 * @property string $status PENDING, AWAITING_OWNER, RUNNING, CONFIRMED, CANCELLED, REJECTED, EXPIRED or FAILED
 */
class AiPendingAction extends Model
{
    protected $table = 'ai_pending_actions';
    public $timestamps = false;
    protected $fillable = [
        'run_id', 'user_id', 'department', 'operation', 'params', 'reason', 'risk', 'status', 'result', 'error',
        'expires_at', 'decided_at', 'created_at', 'approval', 'approved_by', 'decision_note',
    ];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'decided_at' => 'datetime', 'created_at' => 'datetime'];
    }
}
