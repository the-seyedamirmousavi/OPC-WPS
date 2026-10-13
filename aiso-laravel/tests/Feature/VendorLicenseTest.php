<?php

namespace Tests\Feature;

use App\Models\AppUser;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\LicenseEvent;
use App\Models\SystemSetting;
use App\Services\VendorAuth;
use App\Support\Totp;
use Database\Seeders\AisoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * The software vendor's console: hidden sign-in on the normal form, company and licence terms, owner provisioning,
 * and the licence being enforced on everyone else.
 */
class VendorLicenseTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Passw0rd!test';
    private const VENDOR_USER = 'dev-secret9x';
    private const VENDOR_PASSWORD = 'Vendor-Passw0rd-long-1';

    protected function setUp(): void
    {
        parent::setUp();
        config(['aiso.seed.enabled' => true, 'aiso.seed.password' => self::PASSWORD, 'aiso.seed.language' => 'en']);
        $this->seed(AisoSeeder::class);
        config(['aiso.vendor' => VendorAuth::hashCredentials(self::VENDOR_USER, self::VENDOR_PASSWORD) + ['idle_minutes' => 30]]);
    }

    private function vendorSignIn(): void
    {
        $this->postJson('/api/auth/login', ['userId' => self::VENDOR_USER, 'password' => self::VENDOR_PASSWORD])
            ->assertOk()->assertJsonPath('vendor', true);
    }

    private function as(string $userId): AppUser
    {
        $user = AppUser::query()->find($userId);
        $this->actingAs($user);

        return $user;
    }

    private function company(array $overrides = []): Company
    {
        return Company::query()->create(array_merge([
            'id' => 1, 'installation_id' => '00000000-0000-4000-8000-000000000001', 'name' => 'Acme',
            'plan' => 'UNLIMITED', 'status' => 'ACTIVE', 'grace_days' => 0, 'allow_llm' => true, 'allow_bale' => true,
            'created_at' => now(), 'updated_at' => now(),
        ], $overrides));
    }

    // ---------------------------------------------------------------- sign-in and hiding

    public function test_vendor_signs_in_on_the_normal_form_and_gets_a_separate_session(): void
    {
        $this->postJson('/api/auth/login', ['userId' => self::VENDOR_USER, 'password' => 'wrong-password-here'])
            ->assertStatus(401)->assertJsonPath('message', 'Invalid user id or password');
        $this->getJson('/api/vendor/me')->assertStatus(404);

        $this->vendorSignIn();
        $this->getJson('/api/vendor/me')->assertOk()->assertJsonPath('vendor', true);

        // the vendor is not an application user: no role, no access to the application's API
        $this->getJson('/api/auth/me')->assertStatus(401);
        $this->getJson('/api/operations')->assertStatus(401);

        $this->postJson('/api/vendor/logout')->assertOk();
        $this->getJson('/api/vendor/me')->assertStatus(404);
    }

    public function test_console_answers_404_to_everyone_without_a_vendor_session(): void
    {
        $this->getJson('/api/vendor/company')->assertStatus(404)->assertJsonPath('message', 'Not found');
        foreach (['owner', 'manager', 'user1'] as $id) {
            $this->as($id);
            $this->getJson('/api/vendor/company')->assertStatus(404);
            $this->putJson('/api/vendor/company', ['name' => 'X'])->assertStatus(404);
        }
    }

    public function test_console_is_off_without_configured_credentials(): void
    {
        config(['aiso.vendor' => ['salt' => '', 'user_hash' => '', 'password_hash' => '', 'idle_minutes' => 30]]);

        $this->postJson('/api/auth/login', ['userId' => self::VENDOR_USER, 'password' => self::VENDOR_PASSWORD])
            ->assertStatus(401);
        $this->getJson('/api/vendor/me')->assertStatus(404);
    }

    public function test_vendor_session_ends_after_the_idle_time(): void
    {
        $this->vendorSignIn();
        $this->withSession([VendorAuth::SESSION_KEY => time() - 31 * 60])->getJson('/api/vendor/me')->assertStatus(404);
    }

    public function test_failed_sign_ins_are_rate_limited(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/auth/login', ['userId' => 'owner', 'password' => 'wrong-' . $i])->assertStatus(401);
        }
        $this->postJson('/api/auth/login', ['userId' => 'owner', 'password' => self::PASSWORD])
            ->assertStatus(429)->assertJsonPath('message', 'Too many sign-in attempts. Try again in a minute.');
    }

    public function test_credentials_command_prints_only_hashes_for_the_env_file(): void
    {
        $code = Artisan::call('aiso:vendor-credentials', [
            '--print-only' => true, '--username' => 'dev-testuser1', '--password' => 'abcdefghijkl1234',
        ]);
        $out = Artisan::output();

        $this->assertSame(0, $code);
        $this->assertStringContainsString("AISO_VENDOR_USER_HASH='", $out);
        $this->assertStringContainsString("AISO_VENDOR_PASSWORD_HASH='", $out);
        $hashLines = collect(explode("\n", $out))->filter(fn ($l) => str_starts_with($l, 'AISO_VENDOR_'))->implode("\n");
        $this->assertStringNotContainsString('dev-testuser1', $hashLines);
        $this->assertStringNotContainsString('abcdefghijkl1234', $hashLines);
    }

    // ---------------------------------------------------------------- company and licence terms

    public function test_company_and_period_plans(): void
    {
        $this->vendorSignIn();
        $this->putJson('/api/vendor/license/period', ['plan' => 'TRIAL'])->assertStatus(409);

        $this->putJson('/api/vendor/company', ['name' => 'Acme Co', 'contactName' => 'Sara'])
            ->assertOk()->assertJsonPath('company.name', 'Acme Co')
            ->assertJsonPath('license.plan', 'UNLIMITED')->assertJsonPath('state.state', 'ACTIVE')
            ->assertJsonPath('license.expiresAt', null);
        $this->putJson('/api/vendor/company', ['name' => ' '])->assertStatus(400);

        $this->putJson('/api/vendor/license/period', ['plan' => 'TRIAL', 'startsAt' => '2026-01-01'])
            ->assertOk()->assertJsonPath('license.expiresAt', '2026-01-31T00:00:00.000Z');
        $this->putJson('/api/vendor/license/period', ['plan' => 'YEARLY', 'startsAt' => '2026-01-01'])
            ->assertOk()->assertJsonPath('license.expiresAt', '2027-01-01T00:00:00.000Z');
        $this->putJson('/api/vendor/license/period', ['plan' => 'CUSTOM', 'startsAt' => '2026-01-01', 'days' => 10])
            ->assertOk()->assertJsonPath('license.expiresAt', '2026-01-11T00:00:00.000Z');
        $this->putJson('/api/vendor/license/period', ['plan' => 'CUSTOM', 'startsAt' => '2026-01-01', 'expiresAt' => '2026-02-01'])
            ->assertOk()->assertJsonPath('license.expiresAt', '2026-02-01T23:59:59.000Z'); // end of that day (stored to the second)
        $this->putJson('/api/vendor/license/period', ['plan' => 'CUSTOM', 'startsAt' => '2026-01-01'])->assertStatus(400);
        $this->putJson('/api/vendor/license/period', ['plan' => 'CUSTOM', 'startsAt' => '2026-03-01', 'expiresAt' => '2026-02-01'])
            ->assertStatus(400);
        $this->putJson('/api/vendor/license/period', ['plan' => 'UNLIMITED'])
            ->assertOk()->assertJsonPath('license.expiresAt', null)->assertJsonPath('state.state', 'ACTIVE');
    }

    public function test_extend_suspend_and_resume(): void
    {
        $this->vendorSignIn();
        $this->putJson('/api/vendor/company', ['name' => 'Acme'])->assertOk();
        $this->postJson('/api/vendor/license/extend', ['days' => 5])->assertStatus(409);

        $this->putJson('/api/vendor/license/period', ['plan' => 'CUSTOM', 'days' => 10])->assertOk();
        $before = Company::current()->expires_at;
        $this->postJson('/api/vendor/license/extend', ['days' => 5])->assertOk();
        $this->assertSame(5, (int) round($before->diffInDays(Company::current()->expires_at)));

        $this->postJson('/api/vendor/license/suspend', ['reason' => ' '])->assertStatus(400);
        $this->postJson('/api/vendor/license/suspend', ['reason' => 'unpaid invoice'])
            ->assertOk()->assertJsonPath('state.state', 'SUSPENDED')->assertJsonPath('state.access', 'BLOCKED');
        $this->postJson('/api/vendor/license/resume')->assertOk()->assertJsonPath('state.state', 'ACTIVE');
    }

    public function test_state_shows_expiring_soon(): void
    {
        $this->company(['plan' => 'CUSTOM', 'starts_at' => now()->subDays(20), 'expires_at' => now()->addDays(5)]);
        $this->vendorSignIn();
        $this->getJson('/api/vendor/company')->assertOk()
            ->assertJsonPath('state.state', 'EXPIRING')->assertJsonPath('state.access', 'FULL')->assertJsonPath('state.daysLeft', 5);
    }

    // ---------------------------------------------------------------- the licence applies to everyone else

    public function test_without_a_company_or_with_an_unlimited_one_nothing_is_restricted(): void
    {
        $this->postJson('/api/auth/login', ['userId' => 'owner', 'password' => self::PASSWORD])->assertOk();
        $this->company();
        $this->postJson('/api/auth/login', ['userId' => 'manager', 'password' => self::PASSWORD])->assertOk();
    }

    public function test_expired_suspended_or_not_started_licence_blocks_sign_in_and_the_api(): void
    {
        $cases = [
            [['plan' => 'CUSTOM', 'starts_at' => now()->subDays(40), 'expires_at' => now()->subDays(10)], 'The license has expired. Contact your software vendor.'],
            [['status' => 'SUSPENDED', 'suspended_reason' => 'x'], 'The license is suspended. Contact your software vendor.'],
            [['plan' => 'CUSTOM', 'starts_at' => now()->addDays(3), 'expires_at' => now()->addDays(30)], 'The license has not started yet. Contact your software vendor.'],
        ];
        foreach ($cases as [$terms, $message]) {
            Company::query()->delete();
            $this->company($terms);

            $this->postJson('/api/auth/login', ['userId' => 'owner', 'password' => self::PASSWORD])
                ->assertStatus(403)->assertJsonPath('message', $message);

            $this->as('owner');
            $this->getJson('/api/dashboard/owner')->assertStatus(401)->assertJsonPath('message', $message);
        }
    }

    public function test_grace_period_is_read_only(): void
    {
        $this->company(['plan' => 'CUSTOM', 'starts_at' => now()->subDays(40), 'expires_at' => now()->subDays(2), 'grace_days' => 5]);

        $this->postJson('/api/auth/login', ['userId' => 'manager', 'password' => self::PASSWORD])->assertOk();
        $this->as('manager');
        $this->getJson('/api/operations')->assertOk();
        $this->postJson('/api/assignments/suggest', [])->assertStatus(423)
            ->assertJsonPath('message', 'The license has expired; the system is read-only. Contact your software vendor.');

        $this->vendorSignIn();
        $this->getJson('/api/vendor/company')->assertOk()->assertJsonPath('state.state', 'GRACE');
    }

    public function test_user_limit_applies_to_the_owner_but_not_to_the_vendor(): void
    {
        $this->company(['max_users' => AppUser::query()->where('active', true)->count()]);

        $this->as('owner');
        $this->postJson('/api/users', ['id' => 'extra1', 'fullName' => 'Extra', 'role' => 'USER_1', 'password' => 'abcdefgh1'])
            ->assertStatus(403)
            ->assertJsonPath('message', 'License limit reached: at most 5 active users are allowed. Contact your software vendor.');

        $this->vendorSignIn();
        $this->postJson('/api/vendor/users', ['id' => 'extra1', 'fullName' => 'Extra', 'role' => 'USER_1'])
            ->assertOk()->assertJsonPath('user.role', 'USER_1');
        $this->getJson('/api/vendor/company')->assertOk()->assertJsonPath('usage.activeUsers', 6);
    }

    public function test_llm_switch_makes_assignment_use_the_algorithm(): void
    {
        config(['aiso.llm.api_key' => 'k', 'aiso.llm.model' => 'm']);
        $this->company(['allow_llm' => false]);

        $this->as('manager');
        $this->getJson('/api/settings')->assertOk()->assertJsonPath('llmAvailable', false);
        $run = $this->postJson('/api/assignments/suggest', ['mode' => 'LLM'])->assertOk()
            ->assertJsonPath('requestedMode', 'LLM')->assertJsonPath('effectiveMode', 'ALGORITHM')->assertJsonPath('fallback', true);
        $this->assertStringContainsString('not included in this license', implode(' ', $run->json('warnings')));

        Company::current()->update(['allow_llm' => true]);
        $this->getJson('/api/settings')->assertOk()->assertJsonPath('llmAvailable', true);
    }

    public function test_bale_switch_blocks_the_link_code(): void
    {
        $this->company(['allow_bale' => false]);
        $this->as('user1');
        $this->postJson('/api/me/bale-link-code')->assertStatus(403)
            ->assertJsonPath('message', 'This feature is not included in your license. Contact your software vendor.');

        Company::current()->update(['allow_bale' => true]);
        $this->postJson('/api/me/bale-link-code')->assertOk();
    }

    public function test_rules_are_saved_through_the_console(): void
    {
        $this->vendorSignIn();
        $this->putJson('/api/vendor/company', ['name' => 'Acme'])->assertOk();
        $this->putJson('/api/vendor/license/rules', [
            'graceDays' => 7, 'maxUsers' => 12, 'maxActiveProjects' => null, 'allowLlm' => false, 'allowBale' => true,
        ])->assertOk()
            ->assertJsonPath('license.graceDays', 7)->assertJsonPath('license.maxUsers', 12)
            ->assertJsonPath('license.maxActiveProjects', null)->assertJsonPath('license.allowLlm', false);
        $this->putJson('/api/vendor/license/rules', ['graceDays' => 400, 'allowLlm' => true, 'allowBale' => true])->assertStatus(400);
    }

    // ---------------------------------------------------------------- owner, users and settings

    public function test_vendor_creates_the_owner_who_can_then_sign_in(): void
    {
        $this->vendorSignIn();
        $r = $this->putJson('/api/vendor/owner', ['id' => 'acme-owner', 'fullName' => 'Acme Owner'])->assertOk()
            ->assertJsonPath('user.role', 'OWNER')->assertJsonPath('user.mustChangePassword', true);
        $temp = $r->json('temporaryPassword');
        $this->assertIsString($temp);
        $this->assertSame(12, strlen($temp));

        // the previous owner becomes a manager; the system's owner id follows
        $this->assertSame('MANAGER', AppUser::query()->find('owner')->user_role);
        $this->assertSame('acme-owner', SystemSetting::current()->owner_id);
        $this->getJson('/api/vendor/owner')->assertOk()->assertJsonPath('owner.id', 'acme-owner');

        $this->postJson('/api/vendor/logout')->assertOk();
        $this->postJson('/api/auth/login', ['userId' => 'acme-owner', 'password' => $temp])
            ->assertOk()->assertJsonPath('user.role', 'OWNER');

        $this->vendorSignIn();
        $again = $this->postJson('/api/vendor/owner/reset-password', [])->assertOk()->json('temporaryPassword');
        $this->assertNotSame($temp, $again);
        $this->postJson('/api/vendor/logout')->assertOk();
        $this->postJson('/api/auth/login', ['userId' => 'acme-owner', 'password' => $temp])->assertStatus(401);
        $this->postJson('/api/auth/login', ['userId' => 'acme-owner', 'password' => $again])->assertOk();
    }

    public function test_vendor_manages_users_and_starting_settings(): void
    {
        $this->vendorSignIn();
        $this->postJson('/api/vendor/users', ['id' => 'ali', 'fullName' => 'Ali', 'role' => 'MANAGER', 'password' => 'abcdefgh1'])
            ->assertOk()->assertJsonPath('temporaryPassword', 'abcdefgh1');
        $this->postJson('/api/vendor/users', ['id' => 'ali', 'fullName' => 'Ali', 'role' => 'MANAGER'])->assertStatus(409);
        $this->postJson('/api/vendor/users', ['id' => 'x', 'fullName' => 'X', 'role' => 'MANAGER'])->assertStatus(400);
        $this->postJson('/api/vendor/users', ['id' => 'boss', 'fullName' => 'B', 'role' => 'OWNER'])->assertStatus(400);
        $this->patchJson('/api/vendor/users/ali', ['active' => false])->assertOk()->assertJsonPath('active', false);

        $this->putJson('/api/vendor/settings', [
            'projectName' => 'Acme Plant', 'language' => 'fa', 'maxActiveTasksPerUser' => 4,
            'requireCompletionApproval' => true, 'messengerPlatform' => 'BALE',
        ])->assertOk()->assertJsonPath('projectName', 'Acme Plant')->assertJsonPath('maxActiveTasksPerUser', 4);
        $this->assertSame('BALE', SystemSetting::current()->messenger_platform);
        $this->putJson('/api/vendor/settings', ['language' => 'de'])->assertStatus(400);
        $this->putJson('/api/vendor/settings', ['maxActiveTasksPerUser' => 99])->assertStatus(400);
    }

    // ---------------------------------------------------------------- extra factors: device cookie and authenticator

    private const TOKEN = 'device-token-for-tests-0123456789abcdef0123456789';
    private const SECRET = 'JBSWY3DPEHPK3PXP';

    private function credentials(): array
    {
        return ['userId' => self::VENDOR_USER, 'password' => self::VENDOR_PASSWORD];
    }

    private function requireToken(): void
    {
        config(['aiso.vendor.token_hash' => VendorAuth::tokenHash(self::TOKEN)]);
    }

    private function requireAuthenticator(): void
    {
        config(['aiso.vendor.totp_secret' => self::SECRET]);
    }

    /** A six-digit code that is wrong for the current window (so the test cannot pass by luck). */
    private function wrongCode(): string
    {
        $valid = array_map(fn (int $d) => Totp::code(self::SECRET, time() + $d * 30), [-1, 0, 1]);
        for ($i = 0; $i < 1000; $i++) {
            $c = str_pad((string) (123456 + $i), 6, '0', STR_PAD_LEFT);
            if (!in_array($c, $valid, true)) {
                return $c;
            }
        }
        $this->fail('no wrong code found');
    }

    /** A request that carries (or lacks) the vendor's device cookie, as typed into the browser by hand. */
    private function sendWithDevice(string $method, string $uri, ?string $deviceToken, array $data = []): \Illuminate\Testing\TestResponse
    {
        return $this->call(
            $method, $uri, [], $deviceToken === null ? [] : [VendorAuth::COOKIE => $deviceToken], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            $data === [] ? null : json_encode($data),
        );
    }

    public function test_device_cookie_is_required_when_a_token_is_configured(): void
    {
        $this->requireToken();

        // no cookie or a wrong one: indistinguishable from a wrong password
        $this->sendWithDevice('POST', '/api/auth/login', null, $this->credentials())
            ->assertStatus(401)->assertJsonPath('message', 'Invalid user id or password');
        $this->sendWithDevice('POST', '/api/auth/login', 'wrong-token', $this->credentials())->assertStatus(401);
        $this->sendWithDevice('GET', '/api/vendor/me', self::TOKEN)->assertStatus(404); // not signed in

        $this->sendWithDevice('POST', '/api/auth/login', self::TOKEN, $this->credentials())
            ->assertOk()->assertJsonPath('vendor', true);
        $this->sendWithDevice('GET', '/api/vendor/me', self::TOKEN)->assertOk();
    }

    public function test_a_stolen_session_alone_is_not_enough_when_a_token_is_required(): void
    {
        $this->requireToken();
        $this->sendWithDevice('POST', '/api/auth/login', self::TOKEN, $this->credentials())->assertOk();

        $this->sendWithDevice('GET', '/api/vendor/company', self::TOKEN)->assertOk();
        // the same session without the device cookie, or with another one: the console does not exist
        $this->sendWithDevice('GET', '/api/vendor/company', null)->assertStatus(404);
        $this->sendWithDevice('GET', '/api/vendor/company', 'someone-elses-browser')->assertStatus(404);
    }

    public function test_authenticator_code_is_a_second_step(): void
    {
        $this->requireAuthenticator();

        $this->postJson('/api/auth/login', $this->credentials())
            ->assertOk()->assertJsonPath('vendor', true)->assertJsonPath('otp', true);
        // the password alone grants nothing
        $this->getJson('/api/vendor/me')->assertStatus(404);

        $this->postJson('/api/auth/vendor-otp', ['code' => $this->wrongCode()])
            ->assertStatus(401)->assertJsonPath('message', 'Invalid verification code');
        $this->getJson('/api/vendor/me')->assertStatus(404);

        $this->postJson('/api/auth/vendor-otp', ['code' => Totp::code(self::SECRET, time())])
            ->assertOk()->assertJsonPath('vendor', true);
        $this->getJson('/api/vendor/me')->assertOk();
    }

    public function test_an_authenticator_code_works_once_and_only_after_the_password_step(): void
    {
        $this->requireAuthenticator();
        $code = Totp::code(self::SECRET, time());

        // no password step yet: refused, and the code is not used up
        $this->postJson('/api/auth/vendor-otp', ['code' => $code])->assertStatus(401);

        $this->postJson('/api/auth/login', $this->credentials())->assertOk()->assertJsonPath('otp', true);
        $this->postJson('/api/auth/vendor-otp', ['code' => $code])->assertOk();
        $this->postJson('/api/vendor/logout')->assertOk();

        // the same code again (a sniffed one) is refused
        $this->postJson('/api/auth/login', $this->credentials())->assertOk()->assertJsonPath('otp', true);
        $this->postJson('/api/auth/vendor-otp', ['code' => $code])->assertStatus(401);
    }

    public function test_the_pending_password_step_expires(): void
    {
        $this->requireAuthenticator();
        $this->postJson('/api/auth/login', $this->credentials())->assertOk()->assertJsonPath('otp', true);

        $this->withSession([VendorAuth::PENDING_KEY => time() - VendorAuth::OTP_WINDOW_SECONDS - 1])
            ->postJson('/api/auth/vendor-otp', ['code' => Totp::code(self::SECRET, time())])->assertStatus(401);
    }

    public function test_cookie_token_and_authenticator_work_together(): void
    {
        $this->requireToken();
        $this->requireAuthenticator();

        // without the cookie the password is not even recognised, so no code is asked for
        $this->sendWithDevice('POST', '/api/auth/login', null, $this->credentials())->assertStatus(401);

        $this->sendWithDevice('POST', '/api/auth/login', self::TOKEN, $this->credentials())
            ->assertOk()->assertJsonPath('otp', true);
        // the code step needs the cookie as well
        $code = Totp::code(self::SECRET, time());
        $this->sendWithDevice('POST', '/api/auth/vendor-otp', null, ['code' => $code])->assertStatus(401);
        $this->sendWithDevice('POST', '/api/auth/vendor-otp', self::TOKEN, ['code' => $code])->assertOk();
        $this->sendWithDevice('GET', '/api/vendor/me', self::TOKEN)->assertOk();
    }

    public function test_credentials_command_can_create_the_token_and_the_authenticator_alone(): void
    {
        Artisan::call('aiso:vendor-credentials', ['--print-only' => true, '--token-only' => true]);
        $out = Artisan::output();
        $this->assertStringContainsString("AISO_VENDOR_TOKEN_HASH='", $out);
        $this->assertStringNotContainsString('AISO_VENDOR_USER_HASH', $out); // user name and password untouched
        $this->assertStringContainsString('cookie name : ' . VendorAuth::COOKIE, $out);
        // the printed token matches the printed hash
        preg_match('/cookie value: (\S+)/', $out, $token);
        preg_match("/AISO_VENDOR_TOKEN_HASH='([0-9a-f]+)'/", $out, $hash);
        $this->assertSame($hash[1], VendorAuth::tokenHash($token[1]));

        Artisan::call('aiso:vendor-credentials', ['--print-only' => true, '--totp-only' => true]);
        $out = Artisan::output();
        $this->assertStringContainsString("AISO_VENDOR_TOTP_SECRET='", $out);
        $this->assertStringContainsString('otpauth://totp/AISO:vendor?secret=', $out);
    }

    public function test_vendor_actions_stay_out_of_the_audit_trail_owners_can_read(): void
    {
        $this->vendorSignIn();
        $this->putJson('/api/vendor/company', ['name' => 'Acme'])->assertOk();
        $this->putJson('/api/vendor/license/period', ['plan' => 'YEARLY'])->assertOk();
        $this->putJson('/api/vendor/owner', ['id' => 'acme-owner', 'fullName' => 'Acme Owner'])->assertOk();

        $actions = LicenseEvent::query()->pluck('action')->all();
        $this->assertContains('COMPANY_CREATED', $actions);
        $this->assertContains('PERIOD_SET', $actions);
        $this->assertContains('OWNER_SET', $actions);
        $this->getJson('/api/vendor/events')->assertOk()->assertJsonPath('0.action', 'OWNER_SET');

        // user changes are recorded for the application's history, but without naming an actor
        $this->assertTrue(AuditEvent::query()->where('entity_id', 'acme-owner')->exists());
        $this->assertFalse(AuditEvent::query()->where('actor_id', 'like', '%vendor%')->exists());
        $this->assertSame(0, AuditEvent::query()->where('action_name', 'like', '%LICENSE%')->count());
    }
}
