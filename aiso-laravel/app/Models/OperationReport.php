<?php

namespace App\Models;

use App\Enums\ReportType;
use Illuminate\Database\Eloquent\Model;

class OperationReport extends Model
{
    protected $table = 'operation_report';
    public $timestamps = false;
    protected $fillable = ['operation_id', 'user_id', 'report_type', 'progress_percent', 'note', 'created_at'];

    protected function casts(): array
    {
        return ['report_type' => ReportType::class, 'progress_percent' => 'integer', 'created_at' => 'datetime'];
    }
}
