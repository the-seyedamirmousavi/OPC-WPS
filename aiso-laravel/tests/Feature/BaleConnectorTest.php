<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Messaging\BaleBot;
use App\Models\AppUser;
use App\Models\Notification;
use App\Models\SystemSetting;
use App\Services\BaleLinkService;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/** Bale connector: link codes, the bot's commands, the poll command, linked-chat notifications. Never calls Bale. */
class BaleConnectorTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = '123456:TEST-TOKEN';
    private const API = 'https://bale.test';
    private const CHAT = '555';

    protected function setUp(): void
    {
        parent::setUp();
        config(['aiso.bale.token' => self::TOKEN, 'aiso.bale.api_url' => self::API]);
        Cache::flush();
    }

    private function user(string $id, bool $active = true): AppUser
    {
        return AppUser::query()->create([
            'id' => $id,
            'full_name' => 'User ' . $id,
            'user_role' => Role::USER_1,
            'active' => $active,
            'password_hash' => Hash::make('Passw0rd!test'),
            'must_change_password' => false,
            'created_at' => now(),
        ]);
    }

    private function setPlatform(string $platform): void
    {
        SystemSetting::current()->update(['messenger_platform' => $platform]);
    }

    /** @return array<string, mixed> a Bale update carrying a text message */
    private function message(string $text, string $chat = self::CHAT, int $updateId = 1): array
    {
        return ['update_id' => $updateId, 'message' => ['message_id' => 9, 'chat' => ['id' => (int) $chat], 'text' => $text]];
    }

    private function fakeBale(array $extra = []): void
    {
        Http::fake(array_merge([
            self::API . '/bot' . self::TOKEN . '/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
        ], $extra));
    }

    private function sentTo(string $chat, string $containing): bool
    {
        return Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/sendMessage')
            && ($r->data()['chat_id'] ?? null) === $chat
            && str_contains((string) ($r->data()['text'] ?? ''), $containing))->isNotEmpty();
    }

    public function test_link_code_is_eight_unambiguous_characters_and_is_shown_to_the_user(): void
    {
        $this->user('user1');
        $this->actingAs(AppUser::query()->find('user1'));

        $response = $this->postJson('/api/me/bale-link-code')->assertOk()->assertJsonPath('expiresInMinutes', 15);
        $this->assertMatchesRegularExpression('/\A[ABCDEFGHJKMNPQRSTUVWXYZ23456789]{8}\z/', $response->json('code'));
    }

    public function test_a_code_links_the_chat_once(): void
    {
        $user = $this->user('user1');
        $links = app(BaleLinkService::class);
        $code = $links->createLinkCode($user);

        $linked = $links->consume(strtolower($code), self::CHAT);
        $this->assertNotNull($linked);
        $this->assertSame('user1', $linked->id);
        $this->assertSame(self::CHAT, $user->fresh()->messenger_id);

        $this->assertNull($links->consume($code, '777'), 'a used code must not work again');
    }

    public function test_wrong_and_expired_codes_are_rejected(): void
    {
        $user = $this->user('user1');
        $links = app(BaleLinkService::class);

        $this->assertNull($links->consume('ABCDEFGH', self::CHAT));
        $this->assertNull($links->consume('not a code', self::CHAT));

        $code = $links->createLinkCode($user);
        $this->travel(16)->minutes();
        try {
            $this->assertNull($links->consume($code, self::CHAT), 'a code older than 15 minutes must not work');
        } finally {
            $this->travelBack();
        }
        $this->assertNull($user->fresh()->messenger_id);
    }

    public function test_a_code_of_an_inactive_account_does_not_link(): void
    {
        $links = app(BaleLinkService::class);
        $code = $links->createLinkCode($this->user('user1', active: false));

        $this->assertNull($links->consume($code, self::CHAT));
    }

    public function test_linking_a_chat_detaches_it_from_the_previous_account(): void
    {
        $first = $this->user('user1');
        $second = $this->user('user2');
        $links = app(BaleLinkService::class);
        $links->consume($links->createLinkCode($first), self::CHAT);

        $links->consume($links->createLinkCode($second), self::CHAT);

        $this->assertNull($first->fresh()->messenger_id);
        $this->assertSame(self::CHAT, $second->fresh()->messenger_id);
    }

    public function test_start_replies_with_instructions_to_the_same_chat(): void
    {
        $this->fakeBale();

        app(BaleBot::class)->handleUpdate($this->message('/start'));

        $this->assertTrue($this->sentTo(self::CHAT, '/link CODE'));
    }

    public function test_link_command_stores_the_chat_and_replies_success(): void
    {
        $this->fakeBale();
        $user = $this->user('user1');
        $code = app(BaleLinkService::class)->createLinkCode($user);

        app(BaleBot::class)->handleUpdate($this->message('/link ' . $code));

        $this->assertSame(self::CHAT, $user->fresh()->messenger_id);
        $this->assertTrue($this->sentTo(self::CHAT, 'اتصال انجام شد'));
    }

    public function test_link_command_with_a_wrong_code_replies_failure_and_links_nothing(): void
    {
        $this->fakeBale();
        $user = $this->user('user1');

        app(BaleBot::class)->handleUpdate($this->message('/link ABCDEFGH'));

        $this->assertNull($user->fresh()->messenger_id);
        $this->assertTrue($this->sentTo(self::CHAT, 'کد نامعتبر'));
    }

    public function test_unlink_clears_the_chat_and_replies(): void
    {
        $this->fakeBale();
        $user = $this->user('user1');
        app(BaleLinkService::class)->consume(app(BaleLinkService::class)->createLinkCode($user), self::CHAT);

        app(BaleBot::class)->handleUpdate($this->message('/unlink'));

        $this->assertNull($user->fresh()->messenger_id);
        $this->assertTrue($this->sentTo(self::CHAT, 'قطع شد'));
    }

    public function test_other_text_gets_help_and_updates_without_text_are_ignored(): void
    {
        $this->fakeBale();

        app(BaleBot::class)->handleUpdate($this->message('hello'));
        $this->assertTrue($this->sentTo(self::CHAT, '/link CODE'));

        Http::fake();
        app(BaleBot::class)->handleUpdate(['update_id' => 2, 'message' => ['chat' => ['id' => 555], 'photo' => []]]);
        Http::assertNothingSent();
    }

    public function test_status_and_unlink_endpoints_never_return_the_chat_id(): void
    {
        $user = $this->user('user1');
        $this->actingAs($user);
        $this->getJson('/api/me/bale')->assertOk()->assertJsonPath('connected', false)
            ->assertJsonPath('platform', 'IN_APP');

        app(BaleLinkService::class)->consume(app(BaleLinkService::class)->createLinkCode($user), self::CHAT);
        $this->actingAs($user->fresh());

        $this->getJson('/api/me/bale')->assertOk()->assertJsonPath('connected', true)
            ->assertJsonMissingPath('messengerId');
        $this->deleteJson('/api/me/bale')->assertOk();
        $this->assertNull($user->fresh()->messenger_id);
        $this->actingAs($user->fresh());
        $this->getJson('/api/me/bale')->assertJsonPath('connected', false);
    }

    public function test_endpoints_require_sign_in(): void
    {
        $this->getJson('/api/me/bale')->assertStatus(401);
        $this->postJson('/api/me/bale-link-code')->assertStatus(401);
    }

    public function test_poll_once_answers_updates_and_moves_the_offset_forward(): void
    {
        $user = $this->user('user1');
        $code = app(BaleLinkService::class)->createLinkCode($user);
        Http::fake([
            self::API . '/bot' . self::TOKEN . '/getUpdates' => Http::response(['ok' => true, 'result' => [
                $this->message('/link ' . $code, self::CHAT, 10),
                $this->message('hi', self::CHAT, 11),
            ]]),
            self::API . '/bot' . self::TOKEN . '/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
        ]);

        $this->assertSame(0, Artisan::call('bale:poll', ['--once' => true]));

        $this->assertSame(12, Cache::get('bale:offset'));
        $this->assertSame(self::CHAT, $user->fresh()->messenger_id);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/getUpdates')
            && $r->data()['offset'] === 0 && $r->data()['timeout'] === 25);
    }

    public function test_poll_backs_off_and_fails_when_bale_rejects_the_token(): void
    {
        Sleep::fake();
        Http::fake([
            self::API . '/bot' . self::TOKEN . '/getUpdates' => Http::response(['ok' => false, 'error_code' => 401, 'description' => 'Unauthorized'], 401),
        ]);

        $this->assertSame(1, Artisan::call('bale:poll', ['--once' => true]));
        Sleep::assertSleptTimes(1);
        $this->assertNull(Cache::get('bale:offset'));
    }

    public function test_client_reads_the_bot_identity_from_the_token_url(): void
    {
        Http::fake([
            self::API . '/bot' . self::TOKEN . '/getMe' => Http::response(['ok' => true, 'result' => ['id' => 42, 'is_bot' => true]]),
        ]);

        $this->assertSame(42, app(\App\Messaging\BaleClient::class)->getMe()['id']);
        Http::assertSent(fn (Request $r) => $r->url() === self::API . '/bot' . self::TOKEN . '/getMe');
    }

    public function test_poll_refuses_to_start_without_a_token(): void
    {
        config(['aiso.bale.token' => '']);
        Http::fake();

        $this->assertSame(1, Artisan::call('bale:poll', ['--once' => true]));
        Http::assertNothingSent();
    }

    public function test_bale_platform_sends_the_notification_to_the_linked_chat(): void
    {
        $this->fakeBale();
        $this->setPlatform('BALE');
        $user = $this->user('user1');
        app(BaleLinkService::class)->consume(app(BaleLinkService::class)->createLinkCode($user), self::CHAT);

        app(NotificationService::class)->notifyUser('user1', 'TASK_ASSIGNED', 'Task A is assigned to you', null);

        $this->assertTrue($this->sentTo(self::CHAT, 'Task A is assigned to you'));
        $this->assertSame('SENT', Notification::query()->where('recipient_id', 'user1')->value('delivery_status'));
    }

    public function test_a_user_without_a_chat_gets_no_http_call_and_only_the_inbox_message(): void
    {
        $this->fakeBale();
        $this->setPlatform('BALE');
        $this->user('user1');

        app(NotificationService::class)->notifyUser('user1', 'TASK_ASSIGNED', 'Task A', null);

        Http::assertNothingSent();
        $this->assertSame('SENT', Notification::query()->where('recipient_id', 'user1')->value('delivery_status'));
    }

    public function test_in_app_platform_makes_no_http_call(): void
    {
        $this->fakeBale();
        $user = $this->user('user1');
        app(BaleLinkService::class)->consume(app(BaleLinkService::class)->createLinkCode($user), self::CHAT);

        app(NotificationService::class)->notifyUser('user1', 'TASK_ASSIGNED', 'Task A', null);

        Http::assertNothingSent();
    }

    public function test_a_bale_api_error_is_recorded_as_a_failed_delivery(): void
    {
        $this->setPlatform('BALE');
        $user = $this->user('user1');
        app(BaleLinkService::class)->consume(app(BaleLinkService::class)->createLinkCode($user), self::CHAT);
        Http::fake([
            self::API . '/bot' . self::TOKEN . '/sendMessage' => Http::response(['ok' => false, 'error_code' => 403, 'description' => 'bot was blocked by the user'], 403),
        ]);

        app(NotificationService::class)->notifyUser('user1', 'TASK_ASSIGNED', 'Task A', null);

        $row = Notification::query()->where('recipient_id', 'user1')->firstOrFail();
        $this->assertSame('FAILED', $row->delivery_status);
        $this->assertStringContainsString('code 403', (string) $row->delivery_error);
        $this->assertStringNotContainsString(self::TOKEN, (string) $row->delivery_error);
    }

    public function test_a_missing_token_is_recorded_as_a_failed_delivery(): void
    {
        config(['aiso.bale.token' => '']);
        Http::fake();
        $this->setPlatform('BALE');
        $user = $this->user('user1');
        app(BaleLinkService::class)->consume(app(BaleLinkService::class)->createLinkCode($user), self::CHAT);

        app(NotificationService::class)->notifyUser('user1', 'TASK_ASSIGNED', 'Task A', null);

        Http::assertNothingSent();
        $this->assertSame('FAILED', Notification::query()->where('recipient_id', 'user1')->value('delivery_status'));
    }
}
