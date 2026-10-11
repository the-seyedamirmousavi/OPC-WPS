<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Notification extends Model
{
    protected $table = 'notification';
    public $timestamps = false;
    protected $fillable = [
        'recipient_id', 'kind', 'message', 'related_operation_id', 'created_at', 'read_at', 'delivery_status',
        'delivery_error',
    ];

    protected function casts(): array
    {
        return ['created_at' => 'datetime', 'read_at' => 'datetime'];
    }
}
