<?php

namespace App\Models;

use App\Enums\AssignmentMode;
use App\Enums\SystemStatus;
use Illuminate\Database\Eloquent\Model;

/** Singleton row (id = 1) with the project-wide settings. */
class SystemSetting extends Model
{
    protected $table = 'system_setting';
    protected $primaryKey = 'id';
    public $incrementing = false;
    public $timestamps = false;
    protected $fillable = [
        'id', 'project_id', 'project_name', 'owner_id', 'manager_id', 'messenger_platform', 'system_status',
        'data_version', 'configuration_version', 'assignment_mode', 'max_active_tasks_per_user', 'language',
        'require_completion_approval', 'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'system_status' => SystemStatus::class,
            'assignment_mode' => AssignmentMode::class,
            'max_active_tasks_per_user' => 'integer',
            'configuration_version' => 'integer',
            'require_completion_approval' => 'boolean',
            'updated_at' => 'datetime',
        ];
    }

    /** The singleton row, created with defaults on first use. */
    public static function current(): self
    {
        return self::query()->firstOrCreate(['id' => 1], [
            'project_id' => 'P-001',
            'project_name' => 'AISO Project',
            'messenger_platform' => 'IN_APP',
            'system_status' => SystemStatus::ACTIVE,
            'data_version' => '0',
            'configuration_version' => 1,
            'assignment_mode' => AssignmentMode::ALGORITHM,
            'max_active_tasks_per_user' => 3,
            'language' => 'en',
            'require_completion_approval' => false,
            'updated_at' => now(),
        ]);
    }
}
