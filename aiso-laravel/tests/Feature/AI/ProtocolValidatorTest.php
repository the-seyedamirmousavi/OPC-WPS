<?php

namespace Tests\Feature\AI;

use App\AI\IntentCatalog;
use App\AI\ProtocolException;
use App\AI\ProtocolValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AgentFake;
use Tests\TestCase;

/** Every message from the AI is checked against protocol aiso.v1 and the registry before anything happens. */
class ProtocolValidatorTest extends TestCase
{
    use RefreshDatabase;

    private ProtocolValidator $v;
    /** the departments offered to the user */
    private array $allowed = ['production' => ['kind' => 'production'], 'dept-9' => ['kind' => 'general']];

    protected function setUp(): void
    {
        parent::setUp();
        $this->v = new ProtocolValidator(app(IntentCatalog::class));
    }

    private function route(string $json): array
    {
        return $this->v->route($this->v->decode($json), $this->allowed);
    }

    private function answer(string $json): array
    {
        return $this->v->answer($this->v->decode($json), $this->allowed);
    }

    public function test_broken_json_and_text_around_json_are_refused(): void
    {
        foreach (['{"protocol": "aiso.v1", "stage"', 'Sure! ' . AgentFake::route('answer'), '[1,2]', ''] as $bad) {
            try {
                $this->v->decode($bad);
                $this->fail("accepted: {$bad}");
            } catch (ProtocolException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_a_fenced_json_block_is_accepted(): void
    {
        $this->assertSame('answer', $this->route("```json\n" . AgentFake::route('answer') . "\n```")['decision']);
    }

    public function test_the_protocol_and_stage_must_match(): void
    {
        $this->expectException(ProtocolException::class);
        $this->v->route(['protocol' => 'aiso.v2', 'stage' => 'route_result', 'decision' => 'answer'], $this->allowed);
    }

    public function test_an_unknown_decision_is_refused(): void
    {
        $this->expectException(ProtocolException::class);
        $this->route(json_encode(['protocol' => 'aiso.v1', 'stage' => 'route_result', 'decision' => 'delete_everything']));
    }

    public function test_clarify_needs_a_question(): void
    {
        $this->expectException(ProtocolException::class);
        $this->route(AgentFake::route('clarify'));
    }

    public function test_a_valid_fetch_is_normalized(): void
    {
        $r = $this->route(AgentFake::route('fetch', [AgentFake::req('production', 'list_by_status', ['status' => 'READY', 'limit' => 5])]));

        $this->assertSame([['department' => 'production', 'intent' => 'list_by_status', 'params' => ['status' => 'READY', 'limit' => 5]]], $r['requests']);
    }

    public function test_unknown_departments_intents_and_parameters_are_dropped_and_never_returned(): void
    {
        $r = $this->route(AgentFake::route('fetch', [
            AgentFake::req('hr', 'users_summary'),                                    // not offered to this user
            AgentFake::req('production', 'drop_table'),                              // unknown intent
            AgentFake::req('production', 'list_by_status', ['status' => 'DONE']),    // invalid value
            AgentFake::req('production', 'list_by_status', ['status' => 'READY', 'sql' => 'select 1']), // unknown parameter
            AgentFake::req('production', 'status_summary'),                          // the only good one
        ]));

        $this->assertCount(1, $r['requests']);
        $this->assertSame('status_summary', $r['requests'][0]['intent']);
        $this->assertCount(4, $r['dropped']);
    }

    public function test_a_fetch_with_nothing_valid_is_refused(): void
    {
        $this->expectException(ProtocolException::class);
        $this->route(AgentFake::route('fetch', [AgentFake::req('hr', 'users_summary')]));
    }

    public function test_more_than_the_allowed_number_of_requests_per_round_is_cut(): void
    {
        $many = array_fill(0, 8, AgentFake::req('production', 'status_summary'));

        $r = $this->route(AgentFake::route('fetch', $many));

        $this->assertCount(5, $r['requests']);
        $this->assertNotEmpty($r['dropped']);
    }

    public function test_an_answer_needs_text_and_a_fetch_more_needs_requests(): void
    {
        foreach ([AgentFake::answer('  '), AgentFake::fetchMore([])] as $bad) {
            try {
                $this->answer($bad);
                $this->fail('accepted');
            } catch (ProtocolException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_actions_outside_the_config_or_with_bad_parameters_are_dropped(): void
    {
        $a = $this->answer(AgentFake::answer('ok', [
            ['department' => 'production', 'operation' => 'delete_project', 'params' => ['id' => 1], 'reason' => 'x'],
            ['department' => 'production', 'operation' => 'cancel_operation', 'params' => ['operation_id' => 'NOPE-1', 'reason' => 'x'], 'reason' => 'x'],
            ['department' => 'production', 'operation' => 'cancel_operation', 'params' => ['operation_id' => 'OP-1'], 'reason' => 'x'],
            ['department' => 'hr', 'operation' => 'unblock_operation', 'params' => [], 'reason' => 'x'],
            'not even an object',
        ]));

        $this->assertSame([], $a['actions']);
        $this->assertCount(5, $a['dropped']);
        $this->assertSame('ok', $a['text']);
    }
}
