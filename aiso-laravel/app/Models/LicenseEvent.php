<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One entry of the vendor's own log (never shown to owners or managers). */
class LicenseEvent extends Model
{
    protected $table = 'license_event';
    public $timestamps = false;
    protected $fillable = ['occurred_at', 'action', 'detail'];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }
}
