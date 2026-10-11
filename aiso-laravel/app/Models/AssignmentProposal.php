<?php

namespace App\Models;

use App\Enums\AssignmentMode;
use App\Enums\ProposalStatus;
use Illuminate\Database\Eloquent\Model;

class AssignmentProposal extends Model
{
    protected $table = 'assignment_proposal';
    public $timestamps = false;
    protected $fillable = [
        'run_id', 'operation_id', 'user_id', 'source', 'reason', 'planned_start', 'planned_end', 'status',
        'decision_note', 'created_at', 'decided_at', 'decided_by',
    ];

    protected function casts(): array
    {
        return [
            'source' => AssignmentMode::class,
            'status' => ProposalStatus::class,
            'planned_start' => 'datetime',
            'planned_end' => 'datetime',
            'created_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }
}
