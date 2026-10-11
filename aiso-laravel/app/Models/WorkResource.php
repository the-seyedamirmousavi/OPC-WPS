<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkResource extends Model
{
    protected $table = 'work_resource';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;
    protected $fillable = ['id', 'name', 'resource_type', 'responsible_user_id', 'capacity', 'status', 'description'];

    protected function casts(): array
    {
        return ['capacity' => 'integer'];
    }

    public function isActive(): bool
    {
        return $this->status === 'ACTIVE';
    }
}
