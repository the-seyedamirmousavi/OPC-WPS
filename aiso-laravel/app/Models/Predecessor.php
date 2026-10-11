<?php

namespace App\Models;

use App\Enums\DependencyType;
use Illuminate\Database\Eloquent\Model;

class Predecessor extends Model
{
    protected $table = 'predecessor';
    public $timestamps = false;
    protected $fillable = ['operation_id', 'predecessor_id', 'dependency_type', 'start_condition', 'mandatory', 'description'];

    protected function casts(): array
    {
        return [
            'dependency_type' => DependencyType::class,
            'mandatory' => 'boolean',
        ];
    }
}
