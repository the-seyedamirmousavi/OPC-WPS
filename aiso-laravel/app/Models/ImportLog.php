<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ImportLog extends Model
{
    protected $table = 'import_log';
    public $timestamps = false;
    protected $fillable = [
        'imported_at', 'actor_id', 'file_name', 'file_format', 'status', 'error_count', 'summary', 'details',
    ];

    protected function casts(): array
    {
        return ['imported_at' => 'datetime', 'error_count' => 'integer'];
    }
}
