<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A job given to an AI agent. It runs read-only through the AI agent, with the creator's access narrowed to the agent's
 * departments, and the result is kept here and sent to the creator.
 *
 * @property int $id
 * @property string $agent_id
 * @property string $created_by
 * @property string $status PENDING, RUNNING, DONE or FAILED
 */
class AgentTask extends Model
{
    protected $table = 'agent_tasks';
    public $timestamps = false;
    protected $fillable = [
        'agent_id', 'created_by', 'instruction', 'status', 'result', 'error', 'run_id', 'created_at', 'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return ['created_at' => 'datetime', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }
}
