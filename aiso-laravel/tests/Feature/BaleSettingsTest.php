<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\SystemSetting;
use App\Services\BaleConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsAgentWorld;
use Tests\TestCase;

/** The owner sets the Bale bot token in Settings: stored encrypted, never returned, and checked against Bale. */
class BaleSettingsTest extends TestCase
{
    use BuildsAgentWorld, RefreshDatabase;

    private const TOKEN = '123456789:TESTtokenTESTtokenTESTtoken';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedWorld();
        config(['aiso.bale.token' => '', 'aiso.bale.api_url' => 'https://tapi.bale.ai']);
    }

    public function test_the_owner_saves_the_token_encrypted_and_it_is_never_returned(): void
    {
        $this->as('owner');

        $res = $this->putJson('/api/settings/bale', ['token' => self::TOKEN])->assertOk()
            ->assertJsonPath('baleKeySet', true)->assertJsonPath('baleKeySource', 'settings')
            ->assertJsonPath('baleKeyHint', '…oken');
        $this->assertStringNotContainsString(self::TOKEN, $res->getContent());

        $raw = SystemSetting::current()->getRawOriginal('bale_bot_token');
        $this->assertNotSame(self::TOKEN, $raw);
        $this->assertSame(self::TOKEN, Crypt::decryptString($raw));
        $this->assertSame(self::TOKEN, BaleConfig::token());
        $this->assertSame(1, AuditEvent::query()->where('action_name', 'BALE_SETTINGS_CHANGED')->count());
    }

    public function test_a_token_of_the_wrong_shape_is_refused(): void
    {
        $this->as('owner');

        $this->putJson('/api/settings/bale', ['token' => 'not-a-bot-token'])->assertStatus(400);
        $this->assertNull(BaleConfig::token());
    }

    public function test_clearing_the_token_falls_back_to_the_environment(): void
    {
        $this->as('owner');
        $this->putJson('/api/settings/bale', ['token' => self::TOKEN])->assertOk();

        config(['aiso.bale.token' => '987654321:ENVtokenENVtokenENVtoken']);
        $this->putJson('/api/settings/bale', ['clearToken' => true])->assertOk()
            ->assertJsonPath('baleKeySource', 'environment');
        $this->assertSame('987654321:ENVtokenENVtokenENVtoken', BaleConfig::token());
    }

    public function test_a_stored_token_wins_over_the_environment_one(): void
    {
        config(['aiso.bale.token' => '987654321:ENVtokenENVtokenENVtoken']);
        $this->as('owner');

        $this->putJson('/api/settings/bale', ['token' => self::TOKEN])->assertOk();

        $this->assertSame(self::TOKEN, BaleConfig::token());
    }

    public function test_only_the_owner_sets_the_token(): void
    {
        $this->as('manager');

        $this->putJson('/api/settings/bale', ['token' => self::TOKEN])->assertForbidden();
        $this->assertNull(BaleConfig::token());
    }

    public function test_the_connection_check_asks_bale_who_the_bot_is_with_the_stored_token(): void
    {
        Http::fake(['*/getMe' => Http::response(['ok' => true, 'result' => ['username' => 'midaisobot', 'is_bot' => true]])]);
        $this->as('owner');
        $this->putJson('/api/settings/bale', ['token' => self::TOKEN])->assertOk();

        $this->postJson('/api/settings/bale/check')->assertOk()
            ->assertJsonPath('username', 'midaisobot')->assertJsonPath('isBot', true);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/bot' . self::TOKEN . '/getMe'));
    }

    public function test_the_connection_check_says_so_when_no_token_is_set(): void
    {
        $this->as('owner');

        $this->postJson('/api/settings/bale/check')->assertStatus(502);
    }
}
