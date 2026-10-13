<?php

namespace App\Services;

use App\AI\AgentOrchestrator;
use App\AI\DepartmentRegistry;
use App\Models\AppRole;
use App\Exceptions\ApiException;
use App\Models\AgentTask;
use App\Models\AppUser;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * AI agents: virtual users (role AGENT) with a standing job description and a list of departments they may read. An
 * agent never reads more than the person who made it: a task runs with that person's access, narrowed to the agent's
 * departments, and read-only. The agent cannot sign in, receives no operations and takes no licence seat.
 */
class AgentService
{
    /** A task still RUNNING after this many minutes died with its request and is marked failed. */
    private const STALE_MINUTES = 15;

    public function __construct(
        private readonly AuditService $audit,
        private readonly NotificationService $notifications,
        private readonly DepartmentRegistry $registry,
        private readonly AgentOrchestrator $orchestrator,
    ) {
    }

    /**
     * Makes an agent. Only the owner and managers do. $scope is the departments it may read; it can never be wider than
     * what the creator can read, and defaults to all of that.
     *
     * @param string[]|null $scope department slugs (hr, production, dept-{id})
     */
    public function create(AppUser $creator, string $name, string $instructions, ?array $scope): array
    {
        self::assertMayMakeAgents($creator);
        $max = (int) config('aiso.agent.max_active_agents');
        if (AppUser::query()->where('user_role', AppRole::AGENT)->where('active', true)->count() >= $max) {
            throw ApiException::conflict("At most {$max} AI agents can be active at once");
        }

        $slugs = $this->scopeFor($creator, $scope);
        do {
            $id = 'agent-' . Str::lower(Str::random(6));
        } while (AppUser::query()->whereKey($id)->exists());

        $agent = AppUser::query()->create([
            'id' => $id, 'full_name' => trim($name), 'user_role' => AppRole::AGENT, 'active' => true,
            // there is no password to guess: the sign-in refuses agents, and this is random anyway
            'password_hash' => Hash::make(Str::random(64)), 'must_change_password' => false, 'created_at' => CarbonImmutable::now(),
            'agent_instructions' => trim($instructions), 'agent_created_by' => $creator->id,
            'agent_scope' => json_encode($slugs),
        ]);
        $this->audit->record($creator->id, 'AGENT_CREATED', 'Agent', $id, null, $agent->full_name . ' / ' . implode(',', $slugs), null);

        return $this->view($agent, []);
    }

    /** The owner changes an agent's name, instructions, departments or whether it is active. */
    public function update(AppUser $actor, string $id, ?string $name, ?string $instructions, ?array $scope, ?bool $active): array
    {
        if ($actor->user_role !== AppRole::OWNER) {
            throw ApiException::forbidden('Only the owner changes an agent');
        }
        $agent = self::agent($id);
        $before = $agent->full_name . ' / ' . ($agent->active ? 'active' : 'inactive') . ' / ' . implode(',', $agent->agentScope());

        if ($name !== null && trim($name) !== '') {
            $agent->full_name = trim($name);
        }
        if ($instructions !== null && trim($instructions) !== '') {
            $agent->agent_instructions = trim($instructions);
        }
        if ($scope !== null) {
            $creator = AppUser::query()->find($agent->agent_created_by) ?? $actor;
            $agent->agent_scope = json_encode($this->scopeFor($creator, $scope));
        }
        if ($active !== null) {
            $agent->active = $active;
        }
        $agent->save();
        $this->audit->record($actor->id, 'AGENT_UPDATED', 'Agent', $id, $before,
            $agent->full_name . ' / ' . ($agent->active ? 'active' : 'inactive') . ' / ' . implode(',', $agent->agentScope()), null);

        return $this->view($agent, $this->recentTasks([$id])[$id] ?? []);
    }

    /** @return array<int, array> every agent with its latest tasks (owner) */
    public function list(AppUser $actor): array
    {
        if ($actor->user_role !== AppRole::OWNER) {
            throw ApiException::forbidden('Only the owner sees all agents');
        }
        $this->failStale();
        $agents = AppUser::query()->where('user_role', AppRole::AGENT)->orderBy('created_at')->orderBy('id')->get();
        $tasks = $this->recentTasks($agents->pluck('id')->all());

        return $agents->map(fn (AppUser $a) => $this->view($a, $tasks[$a->id] ?? []))->values()->all();
    }

    /** Gives an agent a job. It runs right after this request ends; the result arrives as a notification. */
    public function assignTask(AppUser $actor, string $agentId, string $instruction): array
    {
        self::assertMayMakeAgents($actor);
        $agent = self::agent($agentId);
        if ($actor->user_role !== AppRole::OWNER && $agent->agent_created_by !== $actor->id) {
            throw ApiException::forbidden('This agent belongs to someone else');
        }
        if (!$agent->active) {
            throw ApiException::conflict('This agent is not active');
        }

        $task = AgentTask::query()->create([
            'agent_id' => $agent->id, 'created_by' => $actor->id, 'instruction' => trim($instruction),
            'status' => 'PENDING', 'created_at' => CarbonImmutable::now(),
        ]);
        $this->audit->record($actor->id, 'AGENT_TASK_GIVEN', 'Agent', $agent->id, null, mb_substr(trim($instruction), 0, 200), null);
        // after the response is sent, so the person is not kept waiting for the AI
        app()->terminating(fn () => $this->runTask($task->id));

        return self::taskView($task);
    }

    /** Runs a PENDING task. Read-only, with the agent's creator's access narrowed to the agent's departments. */
    public function runTask(int $taskId): void
    {
        // taken at most once, even if two requests end together
        $claimed = AgentTask::query()->whereKey($taskId)->where('status', 'PENDING')
            ->update(['status' => 'RUNNING', 'started_at' => CarbonImmutable::now()]);
        if ($claimed === 0) {
            return;
        }
        $task = AgentTask::query()->findOrFail($taskId);
        $agent = AppUser::query()->find($task->agent_id);
        $creator = $agent === null ? null : AppUser::query()->find($agent->agent_created_by);
        @set_time_limit(300);

        try {
            if ($agent === null || !$agent->active) {
                throw ApiException::conflict('The agent is not active');
            }
            if ($creator === null || !$creator->isActive()) {
                throw ApiException::conflict('The person who made this agent is not active any more');
            }
            // the standing job description and the snapshot of the system come with the orchestrator's briefing
            $answer = $this->orchestrator->handle(
                "وظیفه‌ی اکنون ({$agent->full_name}): {$task->instruction}\nنتیجه را به‌صورت گزارشی کوتاه و دقیق برای سازنده‌ات بنویس.",
                $creator, [], $agent->agentScope(), true, $agent,
            );
            $task->update(['status' => 'DONE', 'result' => $answer->text, 'run_id' => $answer->runId, 'finished_at' => CarbonImmutable::now()]);
            $this->tell($task, $agent, "Agent {$agent->full_name} finished: " . mb_substr($answer->text, 0, 800));
        } catch (\Throwable $e) {
            if (!$e instanceof ApiException) {
                Log::error('Agent task failed: ' . $e->getMessage());
            }
            $task->update(['status' => 'FAILED', 'error' => mb_substr($e instanceof ApiException ? $e->getMessage() : 'The task could not be completed', 0, 1000),
                'finished_at' => CarbonImmutable::now()]);
            $this->tell($task, $agent, "Agent " . ($agent?->full_name ?? $task->agent_id) . ' could not finish its task: ' . mb_substr((string) $task->error, 0, 300));
        }
    }

    /** @return array<int, array> the latest tasks of one agent (owner) */
    public function tasks(AppUser $actor, string $agentId): array
    {
        if ($actor->user_role !== AppRole::OWNER) {
            throw ApiException::forbidden('Only the owner sees all agents');
        }
        self::agent($agentId);

        return AgentTask::query()->where('agent_id', $agentId)->orderByDesc('id')->limit(50)->get()
            ->map(fn (AgentTask $t) => self::taskView($t))->values()->all();
    }

    // ---- helpers ------------------------------------------------------------------------------------------------

    private static function assertMayMakeAgents(AppUser $user): void
    {
        if (!$user->allows('production.manage') || !$user->isActive()) {
            throw ApiException::forbidden('Only the owner and managers make AI agents');
        }
    }

    private static function agent(string $id): AppUser
    {
        $a = AppUser::query()->find($id);
        if ($a === null || !$a->isAgent()) {
            throw ApiException::notFound("Agent {$id} not found");
        }

        return $a;
    }

    /** The slugs the agent may read: what the creator can read, narrowed to the ones asked for. @return string[] */
    private function scopeFor(AppUser $creator, ?array $scope): array
    {
        $own = array_keys($this->registry->forUser($creator));
        if ($scope === null) {
            return $own;
        }
        $scope = array_values(array_unique($scope));
        foreach ($scope as $slug) {
            if (!in_array($slug, $own, true)) {
                throw ApiException::badRequest("Department {$slug} is not available to you");
            }
        }

        return $scope;
    }

    /** Tells the person who gave the task, and the agent's maker if that is someone else. */
    private function tell(AgentTask $task, ?AppUser $agent, string $message): void
    {
        $people = array_unique(array_filter([$task->created_by, $agent?->agent_created_by]));
        foreach ($people as $id) {
            $this->notifications->notifyUser($id, 'AGENT_TASK', $message, null);
        }
    }

    private function failStale(): void
    {
        AgentTask::query()->where('status', 'RUNNING')->where('started_at', '<', CarbonImmutable::now()->subMinutes(self::STALE_MINUTES))
            ->update(['status' => 'FAILED', 'error' => 'The task did not finish', 'finished_at' => CarbonImmutable::now()]);
    }

    /** @param string[] $agentIds @return array<string, array<int, array>> */
    private function recentTasks(array $agentIds): array
    {
        $out = [];
        foreach (AgentTask::query()->whereIn('agent_id', $agentIds)->orderByDesc('id')->limit(200)->get() as $t) {
            if (count($out[$t->agent_id] ?? []) < 5) {
                $out[$t->agent_id][] = self::taskView($t);
            }
        }

        return $out;
    }

    private function view(AppUser $a, array $tasks): array
    {
        return [
            'id' => $a->id, 'name' => $a->full_name, 'instructions' => $a->agent_instructions, 'scope' => $a->agentScope(),
            'active' => (bool) $a->active, 'createdBy' => $a->agent_created_by,
            'createdAt' => $a->created_at?->utc()->format('Y-m-d\TH:i:s.v\Z'), 'tasks' => $tasks,
        ];
    }

    private static function taskView(AgentTask $t): array
    {
        return [
            'id' => $t->id, 'agentId' => $t->agent_id, 'createdBy' => $t->created_by, 'instruction' => $t->instruction,
            'status' => $t->status, 'result' => $t->result, 'error' => $t->error,
            'createdAt' => $t->created_at?->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'finishedAt' => $t->finished_at?->utc()->format('Y-m-d\TH:i:s.v\Z'),
        ];
    }
}
