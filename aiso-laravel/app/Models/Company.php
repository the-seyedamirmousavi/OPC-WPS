<?php

namespace App\Models;

use App\Enums\CompanyStatus;
use App\Enums\LicensePlan;
use Illuminate\Database\Eloquent\Model;

/**
 * The company this installation is licensed to (singleton row, id = 1) together with its licence terms.
 *
 * @property string $installation_id
 * @property string $name
 * @property LicensePlan $plan
 * @property CompanyStatus $status
 * @property ?\Carbon\CarbonInterface $starts_at
 * @property ?\Carbon\CarbonInterface $expires_at
 */
class Company extends Model
{
    public const ID = 1;

    protected $table = 'company';
    protected $primaryKey = 'id';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = [
        'id', 'installation_id', 'name', 'legal_name', 'contact_name', 'contact_phone', 'contact_email', 'notes',
        'plan', 'status', 'suspended_reason', 'starts_at', 'expires_at', 'grace_days',
        'max_users', 'max_active_projects', 'allow_llm', 'allow_bale', 'created_at', 'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'plan' => LicensePlan::class,
            'status' => CompanyStatus::class,
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
            'grace_days' => 'integer',
            'max_users' => 'integer',
            'max_active_projects' => 'integer',
            'allow_llm' => 'boolean',
            'allow_bale' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public static function current(): ?self
    {
        return self::query()->find(self::ID);
    }
}
