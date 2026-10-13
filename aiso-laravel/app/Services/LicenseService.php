<?php

namespace App\Services;

use App\Enums\CompanyStatus;
use App\Enums\ProjectStatus;
use App\Exceptions\ApiException;
use App\Models\AppUser;
use App\Models\Company;
use App\Models\Project;
use Carbon\CarbonImmutable;

/**
 * What the licence of this installation currently allows. Without a company record nothing is restricted, so
 * installations that predate licensing keep working until the vendor defines the company.
 *
 * Access levels: FULL (normal use), READ_ONLY (after expiry, during the grace period) and BLOCKED (nobody can sign in
 * or work: expired beyond grace, suspended by the vendor, or not started yet).
 */
class LicenseService
{
    public const FULL = 'FULL';
    public const READ_ONLY = 'READ_ONLY';
    public const BLOCKED = 'BLOCKED';

    /** Days before the end of the licence at which the vendor console starts showing "expiring". */
    public const EXPIRING_DAYS = 14;

    /**
     * @return array{state: string, access: string, message: ?string, expiresAt: ?string, daysLeft: ?int, graceEndsAt: ?string}
     *         state is one of NONE, ACTIVE, EXPIRING, GRACE, EXPIRED, SUSPENDED, NOT_STARTED
     */
    public function state(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $c = Company::current();
        if ($c === null) {
            return self::result('NONE', self::FULL, null, null, null, null);
        }
        $expires = $c->expires_at === null ? null : CarbonImmutable::instance($c->expires_at);
        $graceEnds = $expires?->addDays((int) $c->grace_days);
        $daysLeft = $expires === null ? null : (int) ceil(($expires->getTimestamp() - $now->getTimestamp()) / 86400);

        if ($c->status === CompanyStatus::SUSPENDED) {
            return self::result('SUSPENDED', self::BLOCKED, 'The license is suspended. Contact your software vendor.', $expires, $daysLeft, $graceEnds);
        }
        if ($c->starts_at !== null && $now->lt($c->starts_at)) {
            return self::result('NOT_STARTED', self::BLOCKED, 'The license has not started yet. Contact your software vendor.', $expires, $daysLeft, $graceEnds);
        }
        if ($expires === null || $now->lte($expires)) {
            $state = $daysLeft !== null && $daysLeft <= self::EXPIRING_DAYS ? 'EXPIRING' : 'ACTIVE';

            return self::result($state, self::FULL, null, $expires, $daysLeft, $graceEnds);
        }
        if ($graceEnds !== null && $now->lte($graceEnds)) {
            return self::result('GRACE', self::READ_ONLY, 'The license has expired; the system is read-only. Contact your software vendor.', $expires, $daysLeft, $graceEnds);
        }

        return self::result('EXPIRED', self::BLOCKED, 'The license has expired. Contact your software vendor.', $expires, $daysLeft, $graceEnds);
    }

    /** Signing in is refused whenever the licence is blocked (read-only still lets people sign in and look). */
    public function assertSignInAllowed(): void
    {
        $s = $this->state();
        if ($s['access'] === self::BLOCKED) {
            throw ApiException::forbidden((string) $s['message']);
        }
    }

    public function assertCanAddUsers(int $adding = 1): void
    {
        $max = Company::current()?->max_users;
        if ($max === null || $adding <= 0) {
            return;
        }
        // AI agents are virtual users: only people take a seat
        if (AppUser::query()->people()->where('active', true)->count() + $adding > $max) {
            throw ApiException::forbidden("License limit reached: at most {$max} active users are allowed. Contact your software vendor.");
        }
    }

    public function assertCanAddProjects(int $adding = 1): void
    {
        $max = Company::current()?->max_active_projects;
        if ($max === null || $adding <= 0) {
            return;
        }
        if (Project::query()->where('status', ProjectStatus::ACTIVE->value)->count() + $adding > $max) {
            throw ApiException::forbidden("License limit reached: at most {$max} active projects are allowed. Contact your software vendor.");
        }
    }

    public function llmAllowed(): bool
    {
        return Company::current()?->allow_llm ?? true;
    }

    public function baleAllowed(): bool
    {
        return Company::current()?->allow_bale ?? true;
    }

    public function assertBaleAllowed(): void
    {
        if (!$this->baleAllowed()) {
            throw ApiException::forbidden('This feature is not included in your license. Contact your software vendor.');
        }
    }

    private static function result(string $state, string $access, ?string $message, ?CarbonImmutable $expires,
                                   ?int $daysLeft, ?CarbonImmutable $graceEnds): array
    {
        return [
            'state' => $state,
            'access' => $access,
            'message' => $message,
            'expiresAt' => $expires?->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'daysLeft' => $daysLeft,
            'graceEndsAt' => $expires === null || $graceEnds === null || $graceEnds->equalTo($expires)
                ? null : $graceEnds->utc()->format('Y-m-d\TH:i:s.v\Z'),
        ];
    }
}
