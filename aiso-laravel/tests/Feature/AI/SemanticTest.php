<?php

namespace Tests\Feature\AI;

use App\AI\IntentCatalog;
use App\AI\Semantic\TextSimilarity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\AgentFake as F;
use Tests\Support\BuildsAgentWorld;
use Tests\TestCase;

/** The semantic layer: matching Persian wording to real intents, hints, rescue of loose names, and search over data. */
class SemanticTest extends TestCase
{
    use BuildsAgentWorld, RefreshDatabase;

    private array $allowed = [
        'hr' => ['kind' => 'hr', 'name' => 'منابع انسانی'],
        'production' => ['kind' => 'production', 'name' => 'تولید'],
        'dept-3' => ['kind' => 'general', 'name' => 'مالی'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedWorld();
    }

    public function test_text_is_normalized_across_persian_and_arabic_forms_and_digits(): void
    {
        $this->assertSame('کاربر 12', TextSimilarity::normalize('كاربر ۱٢'));
        $this->assertSame('می خواهم', TextSimilarity::normalize("می\u{200c}خواهم"));
        $this->assertSame('op 12', TextSimilarity::normalize('OP-12'));
    }

    public function test_ranking_finds_the_closest_text_even_with_endings_and_typos(): void
    {
        $docs = ['a' => 'فهرست کاربران سامانه', 'b' => 'خلاصه وضعیت تولید', 'c' => 'پیشنهادهای مالی'];

        $this->assertSame('a', TextSimilarity::rank('لیست کاربرا', $docs, 1)[0][0]);
        $this->assertSame('b', TextSimilarity::rank('وضعیت تولیدی', $docs, 1)[0][0]);
        $this->assertSame('c', TextSimilarity::rank('پیشنهاد', $docs, 1)[0][0]);
        $this->assertSame([], TextSimilarity::rank('', $docs));
    }

    public function test_the_phrases_the_model_made_up_are_mapped_to_real_intents(): void
    {
        $c = app(IntentCatalog::class);

        $this->assertSame('list_users', $c->resolveIntent('hr', 'فهرست کاربران', $this->allowed));
        $this->assertSame('users_summary', $c->resolveIntent('hr', 'گزارش کاربران', $this->allowed));
        $this->assertSame('users_summary', $c->resolveIntent('hr', 'تعداد پرسنل', $this->allowed));
        $this->assertSame('list_by_status', $c->resolveIntent('production', 'عملیات مسدود', $this->allowed));
    }

    public function test_nonsense_and_dangerous_names_are_not_guessed(): void
    {
        $c = app(IntentCatalog::class);

        foreach (['drop_table', 'چیز', 'delete everything', 'xyz'] as $given) {
            $this->assertNull($c->resolveIntent('hr', $given, $this->allowed), $given);
        }
        $this->assertNull($c->resolveDepartment('فروش', $this->allowed));
        $this->assertSame('production', $c->resolveDepartment('تولید', $this->allowed));
    }

    public function test_hints_favour_the_department_named_in_the_question(): void
    {
        $c = app(IntentCatalog::class);

        $this->assertSame('hr', $c->hints('منابع انسانی بهم گزارش بده', $this->allowed)[0]['department']);
        $this->assertSame('production.status_summary', $c->hints('تولید چخبر', $this->allowed)[0]['department'] . '.' . $c->hints('تولید چخبر', $this->allowed)[0]['intent']);
        $this->assertSame('operation_detail', $c->hints('رفع انسداد OP-12', $this->allowed)[0]['intent']);
        $this->assertSame([], $c->hints('سلام', $this->allowed));
    }

    public function test_the_model_is_given_the_real_catalogue_and_hints(): void
    {
        $ai = $this->fakeAi([F::route('answer'), F::answer('سلام')]);
        $this->as('owner');

        $this->postJson('/api/chat', ['message' => 'منابع انسانی بهم گزارش بده'])->assertOk();

        $in = $ai->input(0);
        $hr = collect($in['departments'])->firstWhere('slug', 'hr');
        $this->assertEqualsCanonicalizing(
            ['user_detail', 'department_members', 'users_summary', 'list_users', 'search_users'],
            array_column($hr['intents'], 'intent'),
        );
        $this->assertNotEmpty($hr['intents'][0]['for']);
        $this->assertSame('hr', $in['hints'][0]['department']);
    }

    public function test_the_failed_run_from_the_log_now_works_without_a_retry(): void
    {
        // the model chose the right department but wrote the intent in Persian, which used to be rejected twice
        $ai = $this->fakeAi([
            F::route('fetch', [F::req('hr', 'گزارش کاربران')]),
            F::answer('پنج کاربر فعال داریم.'),
        ]);
        $this->as('owner');

        $r = $this->postJson('/api/chat', ['message' => 'منابع انسانی بهم گزارش بده'])->assertOk()
            ->assertJsonPath('reply', 'پنج کاربر فعال داریم.')->assertJsonPath('sources.0.slug', 'hr');

        $this->assertCount(2, $ai->calls); // no correction round
        $fetch = collect($r->json('trace'))->firstWhere('step', 'fetch');
        $this->assertSame('users_summary', $fetch['intent']);
        $this->assertContains('resolved: «گزارش کاربران» → users_summary', $fetch['sent']);
        $this->assertSame(5, $ai->input(1)['data'][0]['data']['total']);
    }

    public function test_a_name_that_cannot_be_resolved_gets_a_correction_that_lists_the_valid_intents(): void
    {
        $ai = $this->fakeAi([
            F::route('fetch', [F::req('hr', 'drop_table')]),
            F::route('fetch', [F::req('hr', 'users_summary')]),
            F::answer('ok'),
        ]);
        $this->as('owner');

        $this->postJson('/api/chat', ['message' => 'x'])->assertOk()->assertJsonPath('reply', 'ok');

        $correction = end($ai->calls[1])['content'];
        $this->assertStringContainsString("unknown intent 'drop_table' for hr", $correction);
        $this->assertStringContainsString('users_summary, list_users, search_users', $correction);
    }

    public function test_search_finds_operations_and_projects_by_meaning_of_their_name(): void
    {
        $this->production();
        $this->operation('OP-1', 'READY');
        $this->operation('OP-2', 'READY');
        DB::table('operation')->where('id', 'OP-1')->update(['name' => 'جوشکاری پل ۳ متری']);
        DB::table('operation')->where('id', 'OP-2')->update(['name' => 'رنگ‌آمیزی باکس']);
        $ai = $this->fakeAi([F::route('fetch', [F::req('production', 'search', ['query' => 'جوش پل'])]), F::answer('OP-1')]);
        $this->as('owner');

        $this->postJson('/api/chat', ['message' => 'عملیات جوش پل کدام است'])->assertOk();

        $rows = $ai->input(1)['data'][0]['data'];
        $this->assertSame('OP-1', $rows[0]['id']);
        $this->assertNotContains('OP-2', array_column($rows, 'id'));
        $this->assertGreaterThan(0.12, $rows[0]['score']);
    }

    public function test_naming_an_id_in_a_search_finds_it_first(): void
    {
        $this->production();
        $this->operation('OP-7', 'READY');
        $ai = $this->fakeAi([F::route('fetch', [F::req('production', 'search', ['query' => 'op-7 please'])]), F::answer('ok')]);
        $this->as('owner');

        $this->postJson('/api/chat', ['message' => 'op-7'])->assertOk();

        $this->assertSame(['OP-7', 1], [$ai->input(1)['data'][0]['data'][0]['id'], $ai->input(1)['data'][0]['data'][0]['score']]);
    }

    public function test_user_search_works_for_the_owner_and_is_not_offered_to_a_manager(): void
    {
        $this->staff($this->production(), [['manager', 'HEAD']]);
        // one fake for both people: the router keeps the controller, and so the AI service it was built with
        $ai = $this->fakeAi([
            F::route('fetch', [F::req('hr', 'search_users', ['query' => 'user 2'])]), F::answer('ok'),                       // owner
            F::route('fetch', [F::req('hr', 'search_users', ['query' => 'user 2'])]), F::route('answer'), F::answer('ok'), // manager
        ]);

        $this->as('owner');
        $this->postJson('/api/chat', ['message' => 'کاربر ۲ کیست'])->assertOk();
        $this->assertSame('user2', $ai->input(1)['data'][0]['data'][0]['id']);
        $this->assertArrayNotHasKey('contact_info', $ai->input(1)['data'][0]['data'][0]);

        // a manager is not offered human resources, so the same request goes nowhere
        $this->as('manager');
        $this->postJson('/api/chat', ['message' => 'کاربر ۲ کیست'])->assertOk();
        $this->assertNotContains('hr', array_column($ai->input(2)['departments'], 'slug'));
        // human resources was read once, for the owner, and never for the manager
        $this->assertSame(1, \App\Models\AiRunStep::query()->where('stage', 'fetch')->where('department', 'hr')->count());
    }

    public function test_every_step_carries_a_short_protocol_summary(): void
    {
        $this->staff($this->production(), [['manager', 'HEAD']]);
        $this->fakeAi([F::route('fetch', [F::req('production', 'status_summary')]), F::answer('ok')]);
        $this->as('manager');

        $trace = $this->postJson('/api/chat', ['message' => 'تولید چخبر'])->assertOk()->json('trace');

        foreach ($trace as $step) {
            $this->assertNotEmpty($step['sent'], $step['step']);
            $this->assertNotEmpty($step['got'], $step['step']);
        }
        $route = $trace[0];
        $this->assertContains('stage: route', $route['sent']);
        $this->assertContains('decision: fetch', $route['got']);
        $this->assertContains('requests: production.status_summary', $route['got']);
        $fetch = $trace[1];
        $this->assertContains('intent: status_summary', $fetch['sent']);
        $this->assertContains('ok: true', $fetch['got']);
    }

    public function test_the_trace_never_carries_data_values_or_secrets(): void
    {
        $this->staff($this->production(), [['manager', 'HEAD']]);
        $this->operation('OP-1', 'READY');
        DB::table('operation')->where('id', 'OP-1')->update(['name' => 'secret-name-xyz']);
        $this->fakeAi([F::route('fetch', [F::req('production', 'list_by_status', ['status' => 'READY'])]), F::answer('ok')]);
        $this->as('manager');

        $json = json_encode($this->postJson('/api/chat', ['message' => 'لیست آماده‌ها'])->assertOk()->json('trace'));

        $this->assertStringNotContainsString('secret-name-xyz', $json); // only sizes, never the rows
        $this->assertStringNotContainsString('test-key', $json);
    }
}
