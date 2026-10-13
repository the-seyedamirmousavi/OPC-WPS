<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** The owner's choice for one assistant action of one department kind: DENY, AUTO, SELF or OWNER. */
class AiActionPolicy extends Model
{
    protected $table = 'ai_action_policies';
    public $timestamps = false;
    protected $fillable = ['kind', 'action', 'mode', 'updated_at'];

    protected function casts(): array
    {
        return ['updated_at' => 'datetime'];
    }
}
