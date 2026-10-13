<?php

namespace Tests\Support;

use App\AI\LlmClient;
use App\Enums\DepartmentRole;
use App\Models\AppUser;
use App\Models\Department;
use App\Services\DepartmentService;
use Database\Seeders\AisoSeeder;
use Illuminate\Support\Facades\DB;

/** Seeded users, the HR and Production departments, operations, and a fake AI service for the agent tests. */
trait BuildsAgentWorld
{
    protected function seedWorld(): void
    {
        // the gate (IntentRouter) is off here: these tests script the route and answer stages only; IntentRouterTest turns it on.
        // They also script the legacy protocol (aiso.v1), so they run the legacy engine; DialogueTest runs the dialogue engine.
        config(['aiso.seed.enabled' => true, 'aiso.seed.password' => 'Passw0rd!test', 'aiso.seed.language' => 'en',
            'aiso.llm.api_key' => 'test-key', 'aiso.llm.model' => 'test-model', 'aiso.llm.embedding_model' => '',
            'aiso.agent.gate.enabled' => false, 'aiso.agent.engine' => 'legacy']);
        $this->seed(AisoSeeder::class);
    }

    protected function user(string $id): AppUser
    {
        return AppUser::query()->findOrFail($id);
    }

    protected function as(string $id): void
    {
        $this->actingAs($this->user($id));
    }

    /** The production department (special role PRODUCTION), created through the service as the owner would. */
    protected function production(): Department
    {
        $existing = Department::query()->where('code', Department::PRODUCTION)->first();
        if ($existing !== null) {
            return $existing;
        }
        $svc = app(DepartmentService::class);
        $d = $svc->create('Production', null, $this->user('owner'));
        $svc->update($d['id'], null, null, null, Department::PRODUCTION, $this->user('owner'));

        return Department::query()->findOrFail($d['id']);
    }

    protected function hr(): Department
    {
        return Department::query()->where('code', Department::HR)->firstOrFail();
    }

    /** @param array<int, array{0: string, 1: string}> $members user id and HEAD or MEMBER */
    protected function staff(Department $d, array $members): void
    {
        app(DepartmentService::class)->setMembers($d->id, array_map(
            static fn (array $m) => ['userId' => $m[0], 'role' => DepartmentRole::from($m[1])], $members,
        ), $this->user('owner'));
    }

    protected function operation(string $id, string $status = 'BLOCKED'): void
    {
        DB::table('project')->insertOrIgnore(['id' => 'P1', 'name' => 'Project one', 'priority' => 1, 'status' => 'ACTIVE', 'created_at' => now()]);
        DB::table('work_resource')->insertOrIgnore(['id' => 'R1', 'name' => 'Machine', 'capacity' => 1, 'status' => 'ACTIVE']);
        DB::table('operation')->insert([
            'id' => $id, 'name' => "Operation {$id}", 'project_id' => 'P1', 'resource_id' => 'R1', 'status' => $status,
            'status_before_block' => $status === 'BLOCKED' ? 'READY' : null, 'block_reason' => $status === 'BLOCKED' ? 'waiting' : null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Replaces the AI service with a fake that answers with the given messages, in order. */
    protected function fakeAi(array $queue): AgentFake
    {
        $fake = new AgentFake($queue);
        $this->app->instance(LlmClient::class, $fake);

        return $fake;
    }
}
