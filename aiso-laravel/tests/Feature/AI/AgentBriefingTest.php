<?php

namespace Tests\Feature\AI;

use App\AI\AgentOrchestrator;
use App\Models\AgentTask;
use App\Models\AppUser;
use App\Services\AgentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AgentFake as F;
use Tests\Support\BuildsAgentWorld;
use Tests\TestCase;

/** An agent is given its job description and a bounded snapshot of what it may read, on every run. */
class AgentBriefingTest extends TestCase
{
    use BuildsAgentWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedWorld();
    }

    /** An agent made by the owner, scoped to the given departments. */
    private function agent(string $instructions, array $scope): AppUser
    {
        $this->as('owner');
        $made = app(AgentService::class)->create($this->user('owner'), 'خسرو', $instructions, $scope);

        return $this->user($made['id']);
    }

    /** One run as the agent, with a fake AI that answers at once. Returns the fake so its inputs can be read. */
    private function runAsAgent(AppUser $agent, string $question = 'گزارش بده'): object
    {
        $ai = $this->fakeAi([F::route('answer'), F::answer('گزارش')]);
        app(AgentOrchestrator::class)->handle($question, $this->user('owner'), [], $agent->agentScope(), true, $agent);

        return $ai;
    }

    public function test_the_job_description_is_given_on_both_stages(): void
    {
        $this->production();
        $agent = $this->agent('هر روز وضعیت عملیات‌های بلاک‌شده را گزارش بده', ['production']);
        $ai = $this->runAsAgent($agent);

        foreach ([0, 1] as $call) {
            $this->assertSame('هر روز وضعیت عملیات‌های بلاک‌شده را گزارش بده', $ai->input($call)['briefing']['agent']['instructions']);
        }
        $this->assertTrue($ai->input(0)['briefing']['agent']['read_only']);
        $this->assertFalse($ai->input(0)['briefing']['agent']['may_propose_actions']);
    }

    public function test_the_briefing_holds_the_open_operations_of_the_scoped_department(): void
    {
        $this->production();
        $this->operation('OP-1', 'BLOCKED');
        $this->operation('OP-2', 'READY');
        $agent = $this->agent('x', ['production']);
        $ai = $this->runAsAgent($agent);

        $briefing = $ai->input(0)['briefing'];
        $this->assertSame(['production'], array_keys($briefing['departments']));
        $ops = $briefing['departments']['production']['open_operations'];
        $this->assertSame(2, $ops['total']);
        $this->assertSame(['OP-1', 'OP-2'], array_column($ops['rows'], 'id'));
        $this->assertSame('BLOCKED', $ops['rows'][0]['status']);
        $this->assertSame('waiting', $ops['rows'][0]['block_reason']);
        $this->assertSame(['BLOCKED' => 1, 'READY' => 1], $briefing['departments']['production']['operations_by_status']);
    }

    public function test_an_agent_scoped_to_production_never_sees_human_resources(): void
    {
        $this->production();
        $agent = $this->agent('x', ['production']);
        $ai = $this->runAsAgent($agent);

        $json = json_encode($ai->input(0)['briefing']);
        $this->assertStringNotContainsString('"hr"', $json);
        $this->assertArrayNotHasKey('users', $ai->input(0)['briefing']['departments']['production']);
    }

    public function test_human_resources_lists_accounts_without_contact_details_or_secrets(): void
    {
        $this->production();
        $this->staff($this->production(), [['user1', 'MEMBER']]);
        $agent = $this->agent('x', ['hr']);
        $ai = $this->runAsAgent($agent);

        $hr = $ai->input(0)['briefing']['departments']['hr'];
        $this->assertGreaterThan(0, $hr['users']['total']);
        // the memberships are part of the list (this is where the department relation is needed)
        $user1 = collect($hr['users']['rows'])->firstWhere('id', 'user1');
        $this->assertSame('Production', $user1['departments'][0]['name']);
        $this->assertSame('MEMBER', $user1['departments'][0]['role']);
        $json = json_encode($hr);
        foreach (['contact_info', 'password_hash', 'messenger_id', 'password'] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
        $this->assertSame(['id', 'name', 'role', 'active', 'departments'], array_keys($hr['users']['rows'][0]));
    }

    public function test_an_agent_scoped_to_nothing_it_cannot_read_gets_no_department_data(): void
    {
        $this->production();
        $agent = $this->agent('x', ['production']);
        $this->as('owner');
        app(AgentService::class)->update($this->user('owner'), $agent->id, null, null, [], null);
        $ai = $this->runAsAgent($this->user($agent->id));

        $this->assertSame([], $ai->input(0)['briefing']['departments']);
    }

    public function test_a_long_list_is_bounded_and_says_how_many_more_there_are(): void
    {
        config(['aiso.agent.briefing.operations' => 2]);
        $this->production();
        foreach (['OP-1', 'OP-2', 'OP-3', 'OP-4', 'OP-5'] as $id) {
            $this->operation($id, 'READY');
        }
        $agent = $this->agent('x', ['production']);
        $ai = $this->runAsAgent($agent);

        $ops = $ai->input(0)['briefing']['departments']['production']['open_operations'];
        $this->assertSame([5, 2, true], [$ops['total'], $ops['shown'], $ops['truncated']]);
        $this->assertCount(2, $ops['rows']);
    }

    public function test_a_person_in_the_chat_gets_the_briefing_without_an_agent_job(): void
    {
        $this->staff($this->production(), [['manager', 'HEAD']]);
        $ai = $this->fakeAi([F::route('answer'), F::answer('سلام')]);
        $this->actingAs($this->user('manager'));
        app(AgentOrchestrator::class)->handle('سلام', $this->user('manager'), [], null, false, null);

        $briefing = $ai->input(0)['briefing'];
        $this->assertSame('MANAGER', $briefing['viewer']['role']);
        $this->assertArrayNotHasKey('agent', $briefing);
        $this->assertSame(['production'], array_keys($briefing['departments']));
    }

    public function test_the_owner_chat_knows_the_agents_jobs_and_what_they_did(): void
    {
        $this->production();
        $agent = $this->agent('هر صبح وضعیت تولید را گزارش بده', ['production', 'hr']);
        AgentTask::query()->create([
            'agent_id' => $agent->id, 'created_by' => 'owner', 'instruction' => 'گزارش صبح', 'status' => 'DONE',
            'result' => 'سه عملیات بلاک‌شده است.', 'created_at' => now(), 'finished_at' => now(),
        ]);
        $ai = $this->fakeAi([F::route('answer'), F::answer('ok')]);
        $this->as('owner');
        app(AgentOrchestrator::class)->handle('ایجنت‌ها چه کاری می‌کنند؟', $this->user('owner'));

        $agents = $ai->input(0)['briefing']['departments']['hr']['agents'];
        $row = collect($agents['rows'])->firstWhere('id', $agent->id);
        $this->assertSame('هر صبح وضعیت تولید را گزارش بده', $row['job_description']);
        $this->assertSame(['production', 'hr'], $row['departments_it_reads']);
        $this->assertSame('سه عملیات بلاک‌شده است.', $row['recent_tasks'][0]['result']);
    }

    public function test_the_trace_shows_which_departments_the_briefing_covers(): void
    {
        $this->production();
        $agent = $this->agent('x', ['production']);
        $this->fakeAi([F::route('answer'), F::answer('ok')]);
        $reply = app(AgentOrchestrator::class)->handle('سلام', $this->user('owner'), [], $agent->agentScope(), true, $agent);

        $route = collect($reply->trace)->firstWhere('step', 'route');
        $this->assertTrue(collect($route['sent'])->contains(fn (string $line) => str_starts_with($line, 'briefing: production, ')));
    }
}
