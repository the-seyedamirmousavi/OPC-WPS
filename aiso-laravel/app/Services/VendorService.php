<?php

namespace App\Services;

use App\Enums\CompanyStatus;
use App\Enums\LicensePlan;
use App\Enums\OperationStatus;
use App\Enums\ProjectStatus;
use App\Models\AppRole;
use App\Exceptions\ApiException;
use App\Models\AppUser;
use App\Models\Company;
use App\Models\LicenseEvent;
use App\Models\Operation;
use App\Models\Project;
use App\Models\SystemSetting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * What the software vendor does for one customer installation: define the company, set the licence period and rules,
 * create the owner (and first users) and the starting settings. The vendor's own actions go to license_event, which
 * owners and managers never see; changes that belong in the application's history (users) are written to the audit
 * trail without naming an actor.
 */
class VendorService
{
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
    private const ID_PATTERN = '/^[A-Za-z0-9._-]{3,64}$/';

    public function __construct(
        private readonly AuditService $audit,
        private readonly LicenseService $license,
    ) {
    }

    // ---------------------------------------------------------------- company and licence

    /** @return array<string, mixed> */
    public function overview(): array
    {
        $c = Company::current();

        return [
            'company' => $c === null ? null : [
                'installationId' => $c->installation_id,
                'name' => $c->name,
                'legalName' => $c->legal_name,
                'contactName' => $c->contact_name,
                'contactPhone' => $c->contact_phone,
                'contactEmail' => $c->contact_email,
                'notes' => $c->notes,
                'createdAt' => self::iso($c->created_at),
            ],
            'license' => $c === null ? null : [
                'plan' => $c->plan->value,
                'status' => $c->status->value,
                'suspendedReason' => $c->suspended_reason,
                'startsAt' => self::iso($c->starts_at),
                'expiresAt' => self::iso($c->expires_at),
                'graceDays' => (int) $c->grace_days,
                'maxUsers' => $c->max_users,
                'maxActiveProjects' => $c->max_active_projects,
                'allowLlm' => (bool) $c->allow_llm,
                'allowBale' => (bool) $c->allow_bale,
            ],
            'state' => $this->license->state(),
            'usage' => [
                'activeUsers' => AppUser::query()->people()->where('active', true)->count(),
                'activeProjects' => Project::query()->where('status', ProjectStatus::ACTIVE->value)->count(),
            ],
        ];
    }

    /** Creates the company on first use. A new company starts UNLIMITED, so defining it never locks anyone out. */
    public function saveCompany(array $v): array
    {
        $name = trim((string) ($v['name'] ?? ''));
        if ($name === '') {
            throw ApiException::badRequest('name: must not be blank');
        }
        $fields = [
            'name' => $name,
            'legal_name' => self::text($v['legalName'] ?? null),
            'contact_name' => self::text($v['contactName'] ?? null),
            'contact_phone' => self::text($v['contactPhone'] ?? null),
            'contact_email' => self::text($v['contactEmail'] ?? null),
            'notes' => self::text($v['notes'] ?? null),
            'updated_at' => CarbonImmutable::now(),
        ];
        $c = Company::current();
        if ($c === null) {
            Company::query()->create($fields + [
                'id' => Company::ID,
                'installation_id' => (string) Str::uuid(),
                'plan' => LicensePlan::UNLIMITED->value,
                'status' => CompanyStatus::ACTIVE->value,
                'created_at' => CarbonImmutable::now(),
            ]);
            $this->log('COMPANY_CREATED', $name);
        } else {
            $c->fill($fields)->save();
            $this->log('COMPANY_UPDATED', $name);
        }

        return $this->overview();
    }

    /**
     * Sets how long the software may be used. UNLIMITED never ends, YEARLY lasts one year, TRIAL 30 days, CUSTOM a
     * number of days or until a given date; all count from the start date (default: now).
     */
    public function savePeriod(array $v): array
    {
        $c = $this->company();
        $plan = LicensePlan::from((string) $v['plan']);
        $now = CarbonImmutable::now();
        $starts = self::date($v['startsAt'] ?? null) ?? $now;

        $expires = match ($plan) {
            LicensePlan::UNLIMITED => null,
            LicensePlan::YEARLY => $starts->addYear(),
            LicensePlan::TRIAL => $starts->addDays(LicensePlan::TRIAL_DAYS),
            LicensePlan::CUSTOM => self::customEnd($starts, $v),
        };

        $c->plan = $plan;
        $c->starts_at = $starts;
        $c->expires_at = $expires;
        $c->updated_at = $now;
        $c->save();
        $this->log('PERIOD_SET', $plan->value . ', start ' . $starts->toDateString() . ', end ' . ($expires?->toDateString() ?? 'none'));

        return $this->overview();
    }

    public function extend(int $days): array
    {
        $c = $this->company();
        if ($c->expires_at === null) {
            throw ApiException::conflict('An unlimited license has no end date to extend.');
        }
        if ($days < 1 || $days > 3650) {
            throw ApiException::badRequest('days must be between 1 and 3650');
        }
        $now = CarbonImmutable::now();
        $base = CarbonImmutable::instance($c->expires_at);
        $c->expires_at = ($base->gt($now) ? $base : $now)->addDays($days);
        $c->updated_at = $now;
        $c->save();
        $this->log('EXTENDED', "+{$days} days, new end " . $c->expires_at->toDateString());

        return $this->overview();
    }

    /** Limits and switches. Null limits mean "unlimited". */
    public function saveRules(array $v): array
    {
        $c = $this->company();
        $c->grace_days = (int) ($v['graceDays'] ?? 0);
        $c->max_users = isset($v['maxUsers']) ? (int) $v['maxUsers'] : null;
        $c->max_active_projects = isset($v['maxActiveProjects']) ? (int) $v['maxActiveProjects'] : null;
        $c->allow_llm = (bool) ($v['allowLlm'] ?? true);
        $c->allow_bale = (bool) ($v['allowBale'] ?? true);
        $c->updated_at = CarbonImmutable::now();
        $c->save();
        $this->log('RULES_SET', sprintf('grace %d d, users %s, projects %s, llm %s, bale %s', $c->grace_days,
            $c->max_users ?? 'unlimited', $c->max_active_projects ?? 'unlimited',
            $c->allow_llm ? 'on' : 'off', $c->allow_bale ? 'on' : 'off'));

        return $this->overview();
    }

    public function suspend(string $reason): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ApiException::badRequest('A reason is required');
        }
        $c = $this->company();
        $c->status = CompanyStatus::SUSPENDED;
        $c->suspended_reason = Str::limit($reason, 500, '');
        $c->updated_at = CarbonImmutable::now();
        $c->save();
        $this->log('SUSPENDED', $reason);

        return $this->overview();
    }

    public function resume(): array
    {
        $c = $this->company();
        $c->status = CompanyStatus::ACTIVE;
        $c->suspended_reason = null;
        $c->updated_at = CarbonImmutable::now();
        $c->save();
        $this->log('RESUMED', null);

        return $this->overview();
    }

    /** @return array[] newest first */
    public function events(int $limit = 200): array
    {
        return LicenseEvent::query()->orderByDesc('id')->limit(min(max($limit, 1), 500))->get()
            ->map(fn (LicenseEvent $e) => ['id' => $e->id, 'occurredAt' => self::iso($e->occurred_at), 'action' => $e->action, 'detail' => $e->detail])
            ->all();
    }

    // ---------------------------------------------------------------- owner and users

    public function owner(): ?array
    {
        $o = AppUser::query()->where('user_role', AppRole::OWNER)->orderBy('created_at')->first();

        return $o === null ? null : UserService::view($o);
    }

    /**
     * Makes this account the owner: creates it, or promotes an existing account. Any other owner becomes a manager.
     * The temporary password is returned once; it is only stored as a hash.
     *
     * @return array{user: array, temporaryPassword: ?string}
     */
    public function setOwner(string $id, string $fullName, ?string $password): array
    {
        $id = self::userId($id);
        $fullName = trim($fullName);
        if ($fullName === '') {
            throw ApiException::badRequest('fullName: must not be blank');
        }
        $plain = self::passwordOrNull($password);

        return DB::transaction(function () use ($id, $fullName, $plain) {
            AppUser::query()->where('user_role', AppRole::OWNER)->where('id', '!=', $id)
                ->update(['user_role' => AppRole::MANAGER]);

            $u = AppUser::query()->find($id);
            $generated = null;
            if ($u === null) {
                $generated = $plain ?? self::temporaryPassword();
                $u = AppUser::query()->create([
                    'id' => $id,
                    'full_name' => $fullName,
                    'user_role' => AppRole::OWNER,
                    'active' => true,
                    'password_hash' => Hash::make($generated),
                    'must_change_password' => true,
                    'created_at' => CarbonImmutable::now(),
                ]);
                $this->audit->record(null, 'USER_CREATED', 'User', $id, null, AppRole::OWNER, null);
            } else {
                $before = $u->user_role . '/' . ($u->active ? 'true' : 'false');
                $u->full_name = $fullName;
                $u->user_role = AppRole::OWNER;
                $u->active = true;
                if ($plain !== null) {
                    $generated = $plain;
                    $u->password_hash = Hash::make($plain);
                    $u->must_change_password = true;
                }
                $u->save();
                $this->audit->record(null, 'USER_UPDATED', 'User', $id, $before, 'OWNER/true', null);
            }
            $s = SystemSetting::current();
            $s->owner_id = $id;
            $s->updated_at = CarbonImmutable::now();
            $s->save();
            $this->log('OWNER_SET', $id);

            return ['user' => UserService::view($u), 'temporaryPassword' => $generated];
        });
    }

    /** @return array{user: array, temporaryPassword: string} */
    public function resetPassword(string $id, ?string $password): array
    {
        $u = $this->user($id);
        $temp = self::passwordOrNull($password) ?? self::temporaryPassword();
        $u->password_hash = Hash::make($temp);
        $u->must_change_password = true;
        $u->save();
        $this->audit->record(null, 'PASSWORD_RESET', 'User', $u->id, null, null, null);
        $this->log('PASSWORD_RESET', $u->id);

        return ['user' => UserService::view($u), 'temporaryPassword' => $temp];
    }

    /** @return array[] */
    public function users(): array
    {
        return AppUser::query()->get()
            ->sortBy(fn (AppUser $u) => $u->user_role . "|" . $u->id)
            ->map(fn (AppUser $u) => UserService::view($u))->values()->all();
    }

    /** @return array{user: array, temporaryPassword: string} */
    public function createUser(string $id, string $fullName, string $role, ?string $password): array
    {
        if ($role === AppRole::OWNER) {
            throw ApiException::badRequest('Use the owner section to set the owner');
        }
        $id = self::userId($id);
        if (AppUser::query()->whereKey($id)->exists()) {
            throw ApiException::conflict("User id '{$id}' already exists");
        }
        $fullName = trim($fullName);
        if ($fullName === '') {
            throw ApiException::badRequest('fullName: must not be blank');
        }
        $temp = self::passwordOrNull($password) ?? self::temporaryPassword();
        $u = AppUser::query()->create([
            'id' => $id,
            'full_name' => $fullName,
            'user_role' => $role,
            'active' => true,
            'password_hash' => Hash::make($temp),
            'must_change_password' => true,
            'created_at' => CarbonImmutable::now(),
        ]);
        $this->audit->record(null, 'USER_CREATED', 'User', $id, null, $role, null);
        $this->log('USER_CREATED', "{$id} ({$role})");

        return ['user' => UserService::view($u), 'temporaryPassword' => $temp];
    }

    public function setActive(string $id, bool $active): array
    {
        $u = $this->user($id);
        if (!$active && $u->active && $u->user_role !== AppRole::OWNER) {
            $open = Operation::query()->where('assigned_user_id', $id)->get()
                ->filter(fn (Operation $o) => $o->status->isActive() || $o->status === OperationStatus::BLOCKED)->count();
            if ($open > 0) {
                throw ApiException::conflict("User {$id} still has {$open} open task(s). Reassign or unassign them first.");
            }
        }
        $before = $u->user_role . '/' . ($u->active ? 'true' : 'false');
        $u->active = $active;
        $u->save();
        $this->audit->record(null, 'USER_UPDATED', 'User', $id, $before, $u->user_role . '/' . ($active ? 'true' : 'false'), null);
        $this->log($active ? 'USER_ACTIVATED' : 'USER_DEACTIVATED', $id);

        return UserService::view($u);
    }

    // ---------------------------------------------------------------- starting settings

    public function settings(): array
    {
        $s = SystemSetting::current();

        return [
            'projectName' => $s->project_name,
            'language' => $s->language,
            'maxActiveTasksPerUser' => (int) $s->max_active_tasks_per_user,
            'requireCompletionApproval' => (bool) $s->require_completion_approval,
            'messengerPlatform' => $s->messenger_platform,
        ];
    }

    public function saveSettings(array $v): array
    {
        $s = SystemSetting::current();
        if (isset($v['projectName']) && trim((string) $v['projectName']) !== '') {
            $s->project_name = trim((string) $v['projectName']);
        }
        if (isset($v['language'])) {
            $s->language = (string) $v['language'];
        }
        if (isset($v['maxActiveTasksPerUser'])) {
            $s->max_active_tasks_per_user = (int) $v['maxActiveTasksPerUser'];
        }
        if (isset($v['requireCompletionApproval'])) {
            $s->require_completion_approval = (bool) $v['requireCompletionApproval'];
        }
        if (isset($v['messengerPlatform'])) {
            $s->messenger_platform = strtoupper((string) $v['messengerPlatform']);
        }
        $s->configuration_version = (int) $s->configuration_version + 1;
        $s->updated_at = CarbonImmutable::now();
        $s->save();
        $this->log('SETTINGS_SET', null);

        return $this->settings();
    }

    // ---------------------------------------------------------------- helpers

    private function company(): Company
    {
        return Company::current() ?? throw ApiException::conflict('Define the company first.');
    }

    private function user(string $id): AppUser
    {
        return AppUser::query()->find($id) ?? throw ApiException::notFound("User {$id} not found");
    }

    private function log(string $action, ?string $detail): void
    {
        LicenseEvent::query()->create([
            'occurred_at' => CarbonImmutable::now(),
            'action' => $action,
            'detail' => $detail === null ? null : Str::limit($detail, 2000, ''),
        ]);
    }

    private static function userId(string $id): string
    {
        $id = trim($id);
        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            throw ApiException::badRequest('User id may only contain letters, digits, dot, dash and underscore (3 to 64 characters)');
        }

        return $id;
    }

    private static function passwordOrNull(?string $password): ?string
    {
        if ($password === null || $password === '') {
            return null;
        }
        if (mb_strlen($password) < 8) {
            throw ApiException::badRequest('Password must be at least 8 characters');
        }

        return $password;
    }

    private static function temporaryPassword(): string
    {
        $out = '';
        $max = strlen(self::ALPHABET) - 1;
        for ($i = 0; $i < 12; $i++) {
            $out .= self::ALPHABET[random_int(0, $max)];
        }

        return $out;
    }

    private static function text(mixed $v): ?string
    {
        $s = trim((string) ($v ?? ''));

        return $s === '' ? null : $s;
    }

    private static function date(mixed $v): ?CarbonImmutable
    {
        $s = trim((string) ($v ?? ''));
        if ($s === '') {
            return null;
        }
        try {
            return CarbonImmutable::parse($s, 'UTC')->utc();
        } catch (\Throwable) {
            throw ApiException::badRequest("Date must look like 2026-10-12, got '{$s}'");
        }
    }

    private static function customEnd(CarbonImmutable $starts, array $v): CarbonImmutable
    {
        $days = isset($v['days']) ? (int) $v['days'] : 0;
        $until = self::date($v['expiresAt'] ?? null);
        if ($days < 1 && $until === null) {
            throw ApiException::badRequest('A custom plan needs a number of days or an end date');
        }
        $end = $days >= 1 ? $starts->addDays($days) : $until->endOfDay();
        if ($end->lte($starts)) {
            throw ApiException::badRequest('The end date must be after the start date');
        }

        return $end;
    }

    private static function iso(mixed $t): ?string
    {
        return $t === null ? null : CarbonImmutable::instance($t)->utc()->format('Y-m-d\TH:i:s.v\Z');
    }
}
