<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One call in a run: a message to the AI and its answer, or one department fetch. */
class AiRunStep extends Model
{
    protected $table = 'ai_run_steps';
    public $timestamps = false;
    protected $fillable = ['run_id', 'stage', 'department', 'input', 'output', 'tokens_in', 'tokens_out', 'error', 'duration_ms', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
