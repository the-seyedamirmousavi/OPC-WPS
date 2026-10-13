<?php

namespace Tests\Feature\AI;

use App\AI\IntentCatalog;
use App\AI\Semantic\Embedder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\AgentFake as F;
use Tests\Support\BuildsAgentWorld;
use Tests\TestCase;

/** The embedding model is optional: used when it exists, skipped quietly when it does not. */
class EmbeddingTest extends TestCase
{
    use BuildsAgentWorld, RefreshDatabase;

    private array $allowed = [
        'hr' => ['kind' => 'hr', 'name' => 'منابع انسانی'],
        'production' => ['kind' => 'production', 'name' => 'تولید'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedWorld();
    }

    private function useModel(): void
    {
        config(['aiso.llm.embedding_model' => 'text-embedding-test']);
    }

    /** A fake embedding service whose vectors say what a text is about: people, production work, welding, or other. */
    private function fakeEmbeddings(): void
    {
        Http::fake(['*/embeddings' => function (Request $r) {
            $groups = [
                0 => ['کاربر', 'کارمند', 'پرسنل', 'همکار', 'users', 'staff', 'employees'],
                1 => ['تولید', 'عملیات', 'production', 'operation'],
                2 => ['جوش', 'weld'],
            ];
            $data = [];
            foreach ($r['input'] as $i => $text) {
                $v = [0.0, 0.0, 0.0, 0.05];
                foreach ($groups as $dim => $words) {
                    foreach ($words as $w) {
                        if (mb_stripos($text, $w) !== false) {
                            $v[$dim] = 1.0;
                        }
                    }
                }
                $data[] = ['index' => $i, 'embedding' => $v];
            }

            return Http::response(['data' => $data]);
        }]);
    }

    private function embeddingCalls(): int
    {
        return Http::recorded(fn (Request $r) => str_contains($r->url(), '/embeddings'))->count();
    }

    public function test_without_a_model_nothing_is_called_and_the_text_engine_works(): void
    {
        config(['aiso.llm.embedding_model' => '']);
        Http::fake();
        $c = app(IntentCatalog::class);

        $this->assertSame('hr', $c->hints('منابع انسانی بهم گزارش بده', $this->allowed)[0]['department']);
        $this->assertSame('lexical (no embedding model)', $c->semanticMode());
        Http::assertNothingSent();
    }

    public function test_a_working_model_finds_what_the_wording_alone_cannot(): void
    {
        $c = app(IntentCatalog::class);
        $this->assertSame([], $c->hints('همکاران شرکت', $this->allowed)); // the text engine alone sees nothing

        $this->useModel();
        $this->fakeEmbeddings();

        $hints = $c->hints('همکاران شرکت', $this->allowed);

        $this->assertNotEmpty($hints);
        $this->assertSame('hr', $hints[0]['department']);
        $this->assertSame('embedding text-embedding-test', $c->semanticMode());
    }

    public function test_the_trace_says_which_mode_was_used(): void
    {
        $this->useModel();
        $this->fakeEmbeddings();
        $ai = $this->fakeAi([F::route('answer'), F::answer('سلام')]);
        $this->as('owner');

        $trace = $this->postJson('/api/chat', ['message' => 'همکاران ما چند نفرند'])->assertOk()->json('trace');

        $this->assertContains('semantic: embedding text-embedding-test', $trace[0]['sent']);
        // the meaning-based hint reached the model: human resources, which the wording alone would not have found
        $this->assertSame('hr', $ai->input(0)['hints'][0]['department']);
    }

    public function test_a_model_the_service_does_not_have_is_skipped_and_not_asked_again(): void
    {
        $this->useModel();
        Http::fake(['*/embeddings' => Http::response(['error' => 'model not found'], 404)]);
        $c = app(IntentCatalog::class);

        // the question still gets its hints, from the text engine
        $this->assertSame('hr', $c->hints('منابع انسانی بهم گزارش بده', $this->allowed)[0]['department']);
        $this->assertTrue(Cache::has('aiso.embeddings.down'));
        $this->assertFalse(app(Embedder::class)->available());
        $this->assertSame('lexical (no embedding model)', $c->semanticMode());

        $c->hints('تولید چخبر', $this->allowed);
        $c->resolveIntent('hr', 'گزارش کاربران', $this->allowed);
        $this->assertSame(1, $this->embeddingCalls()); // asked once, then left alone
    }

    public function test_a_failing_service_never_breaks_the_chat(): void
    {
        $this->useModel();
        Http::fake(['*/embeddings' => Http::response('boom', 500)]);
        $ai = $this->fakeAi([F::route('fetch', [F::req('hr', 'گزارش کاربران')]), F::answer('پنج کاربر.')]);
        $this->as('owner');

        $this->postJson('/api/chat', ['message' => 'منابع انسانی بهم گزارش بده'])->assertOk()->assertJsonPath('reply', 'پنج کاربر.');

        $this->assertCount(2, $ai->calls);
        $this->assertTrue(Cache::has('aiso.embeddings.down'));
    }

    public function test_a_malformed_reply_is_treated_as_unavailable(): void
    {
        $this->useModel();
        Http::fake(['*/embeddings' => Http::response(['data' => [['index' => 0]]])]);

        $this->assertNull(app(Embedder::class)->embed(['a', 'b']));
        $this->assertFalse(app(Embedder::class)->available());
    }

    public function test_search_understands_meaning_across_languages_only_with_the_model(): void
    {
        $this->production();
        $this->operation('OP-1', 'READY');
        $this->operation('OP-2', 'READY');
        DB::table('operation')->where('id', 'OP-1')->update(['name' => 'Welding of the bridge']);
        DB::table('operation')->where('id', 'OP-2')->update(['name' => 'Painting the box']);
        $search = fn () => F::route('fetch', [F::req('production', 'search', ['query' => 'جوشکاری'])]);

        // without the model a Persian word does not find an English name
        $ai = $this->fakeAi([$search(), F::answer('none')]);
        $this->as('owner');
        $this->postJson('/api/chat', ['message' => 'جوشکاری'])->assertOk();
        $this->assertSame([], $ai->input(1)['data'][0]['data']);
    }

    public function test_with_the_model_search_finds_the_operation_by_meaning(): void
    {
        $this->production();
        $this->operation('OP-1', 'READY');
        $this->operation('OP-2', 'READY');
        DB::table('operation')->where('id', 'OP-1')->update(['name' => 'Welding of the bridge']);
        DB::table('operation')->where('id', 'OP-2')->update(['name' => 'Painting the box']);
        $this->useModel();
        $this->fakeEmbeddings();
        $ai = $this->fakeAi([F::route('fetch', [F::req('production', 'search', ['query' => 'جوشکاری'])]), F::answer('OP-1')]);
        $this->as('owner');

        $this->postJson('/api/chat', ['message' => 'جوشکاری'])->assertOk();

        $rows = $ai->input(1)['data'][0]['data'];
        $this->assertSame('OP-1', $rows[0]['id']);
        $this->assertNotContains('OP-2', array_column($rows, 'id'));
    }

    public function test_a_text_is_embedded_once(): void
    {
        $this->useModel();
        $this->fakeEmbeddings();
        $c = app(IntentCatalog::class);

        $c->hints('همکاران شرکت', $this->allowed);
        $first = Http::recorded(fn (Request $r) => true)->first()[0]['input'];
        $c->hints('همکاران ما', $this->allowed);
        $second = Http::recorded(fn (Request $r) => true)->last()[0]['input'];

        $this->assertGreaterThan(5, count($first)); // the question and every intent
        $this->assertCount(1, $second);               // only the new question
    }
}
