<?php

namespace App\Models;

use App\Enums\AssignmentMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AssignmentRun extends Model
{
    protected $table = 'assignment_run';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;
    protected $fillable = [
        'id', 'requested_mode', 'effective_mode', 'fallback', 'model', 'summary', 'warnings', 'unassigned',
        'requested_by', 'created_at', 'duration_ms', 'input_tokens', 'output_tokens',
    ];

    protected function casts(): array
    {
        return [
            'requested_mode' => AssignmentMode::class,
            'effective_mode' => AssignmentMode::class,
            'fallback' => 'boolean',
            'created_at' => 'datetime',
            'duration_ms' => 'integer',
        ];
    }

    public function proposals(): HasMany
    {
        return $this->hasMany(AssignmentProposal::class, 'run_id');
    }
}
