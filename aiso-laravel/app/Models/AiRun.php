<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One question to the AI agent, with its rounds, tokens and outcome. */
class AiRun extends Model
{
    protected $table = 'ai_runs';
    public $timestamps = false;
    protected $fillable = ['user_id', 'question', 'status', 'rounds', 'tokens_in', 'tokens_out', 'error', 'duration_ms', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function steps(): HasMany
    {
        return $this->hasMany(AiRunStep::class, 'run_id');
    }

    /** The person (or the agent) the run was made for. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(AppUser::class, 'user_id');
    }
}
