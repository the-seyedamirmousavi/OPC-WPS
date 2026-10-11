<?php

namespace App\Models;

use App\Enums\ProjectStatus;
use Illuminate\Database\Eloquent\Model;

class Project extends Model
{
    protected $table = 'project';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;
    protected $fillable = ['id', 'name', 'priority', 'due_date', 'status', 'description', 'created_at'];

    protected function casts(): array
    {
        return [
            'priority' => 'integer',
            'due_date' => 'datetime',
            'status' => ProjectStatus::class,
            'created_at' => 'datetime',
        ];
    }
}
