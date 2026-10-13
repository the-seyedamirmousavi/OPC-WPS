<?php

namespace App\AI;

use App\Enums\OperationStatus;
use App\Models\AppRole;
use App\Models\AgentTask;
use App\Models\AppUser;
use App\Models\Department;
use App\Models\DepartmentMember;
use App\Models\DepartmentSuggestion;
use App\Models\Operation;
use App\Models\Project;
use App\Models\SystemSetting;
use App\Models\WorkResource;
use App\Services\DepartmentService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The standing briefing of an AI agent: its own job description, and a snapshot of everything it may read, given with every
 * run. It is built only from the departments the agent is scoped to, with the same rule that the department handlers use,
 * so an agent never sees a figure its creator could not see. Contact details and credentials are never included.
 *
 * Each section is bounded (config aiso.agent.briefing). A section that is longer says how many rows exist, so the agent
 * knows to ask a department intent for the rest.
 */
class AgentBriefing
{
    private const TERMINAL = [OperationStatus::COMPLETED, OperationStatus::CANCELLED];

    public function __construct(private readonly DepartmentService $departments)
    {
    }

    /**
     * Built for every run. $agent is the AI agent doing the run (null for a person in the chat); $viewer is the person whose
     * access the run uses. The departments are the ones the run may read (already narrowed to an agent's scope).
     *
     * @param array<string, array{slug: string, name: string, kind: string, department: Department}> $allowed
     * @return array<string, mixed>
     */
    public function build(?AppUser $agent, array $allowed, AppUser $viewer): array
    {
        $setting = SystemSetting::current();
        $now = CarbonImmutable::now('Asia/Tehran');
        $out = [
            'as_of' => $now->format('Y-m-d H:i') . ' (Asia/Tehran)',
            'system' => [
                'project' => ['id' => $setting->project_id, 'name' => $setting->project_name],
                'language' => $setting->language,
                'assignment_mode' => $setting->assignment_mode->value,
                'planning_goal' => $setting->planning_goal->value,
                'max_active_tasks_per_user' => (int) $setting->max_active_tasks_per_user,
            ],
            'viewer' => ['name' => $viewer->full_name, 'role' => $viewer->user_role],
            'departments' => [],
        ];
        if ($agent !== null) {
            $out['agent'] = [
                'name' => $agent->full_name,
                'instructions' => (string) $agent->agent_instructions,
                'read_only' => true,
                'may_propose_actions' => false,
                'created_by' => $agent->agent_created_by,
            ];
        }

        foreach ($allowed as $slug => $d) {
            $out['departments'][$slug] = match ($d['kind']) {
                'production' => $this->production($d['department'], $now),
                'hr' => $this->hr($now),
                default => $this->general($d['department'], $now),
            };
        }

        return $out;
    }

    /** Projects, every open operation with its state, resources and the executive users who receive work. */
    private function production(Department $d, CarbonImmutable $now): array
    {
        $cap = (array) config('aiso.agent.briefing');
        $byStatus = Operation::query()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')->all();
        $open = Operation::query()->whereNotIn('status', array_map(fn (OperationStatus $s) => $s->value, self::TERMINAL))
            ->orderBy('planned_start')->orderBy('id');

        return [
            'name' => $d->name,
            'operations_by_status' => $byStatus,
            'open_operations' => self::section(
                (clone $open)->count(),
                (clone $open)->limit($cap['operations'])->get(['id', 'name', 'project_id', 'resource_id', 'status', 'assigned_user_id',
                    'responsible_user_id', 'progress_percent', 'planned_start', 'planned_end', 'block_reason']),
                fn (Operation $o) => [
                    'id' => $o->id, 'name' => $o->name, 'project' => $o->project_id, 'resource' => $o->resource_id,
                    'status' => $o->status->value, 'assigned_to' => $o->assigned_user_id, 'responsible' => $o->responsible_user_id,
                    'progress_percent' => $o->progress_percent, 'planned_start' => $o->planned_start?->toDateTimeString(),
                    'planned_end' => $o->planned_end?->toDateTimeString(), 'block_reason' => $o->block_reason,
                ],
            ),
            'projects' => self::section(
                Project::query()->count(),
                Project::query()->orderBy('priority')->orderBy('id')->limit($cap['projects'])->get(),
                fn (Project $p) => [
                    'id' => $p->id, 'name' => $p->name, 'priority' => (int) $p->priority, 'status' => (string) ($p->status->value ?? $p->status),
                    'due_date' => $p->due_date?->toDateString(),
                    'operations' => Operation::query()->where('project_id', $p->id)->count(),
                    'open_operations' => Operation::query()->where('project_id', $p->id)
                        ->whereNotIn('status', array_map(fn (OperationStatus $s) => $s->value, self::TERMINAL))->count(),
                ],
            ),
            'resources' => self::section(
                WorkResource::query()->count(),
                WorkResource::query()->orderBy('sort_order')->orderBy('id')->limit($cap['resources'])->get(),
                fn (WorkResource $r) => [
                    'id' => $r->id, 'name' => $r->name, 'capacity' => (int) $r->capacity, 'status' => $r->status,
                    'responsible_user' => $r->responsible_user_id,
                ],
            ),
            'executive_users' => self::section(
                AppUser::query()->whereIn('user_role', [AppRole::USER_1, AppRole::USER_2, AppRole::USER_3])->count(),
                AppUser::query()->whereIn('user_role', [AppRole::USER_1, AppRole::USER_2, AppRole::USER_3])->orderBy('id')->limit($cap['users'])->get(['id', 'full_name', 'user_role', 'active']),
                fn (AppUser $u) => ['id' => $u->id, 'name' => $u->full_name, 'role' => $u->user_role, 'active' => (bool) $u->active],
            ),
            'predecessor_links' => DB::table('predecessor')->count(),
        ];
    }

    /** Every user account (no contact details), who is in which department, and the agents with their scope. */
    private function hr(CarbonImmutable $now): array
    {
        $cap = (array) config('aiso.agent.briefing');
        $users = AppUser::query()->where('user_role', '!=', AppRole::AGENT);
        $memberships = DepartmentMember::query()->with('department:id,name')->get()->groupBy('user_id');
        $agents = AppUser::query()->where('user_role', AppRole::AGENT);
        $agentList = (clone $agents)->orderBy('id')->limit($cap['agents'])->get(['id', 'full_name', 'active', 'agent_instructions', 'agent_scope', 'agent_created_by', 'created_at']);
        // each agent's latest tasks with what came of them: this is how the assistant learns what the agents actually do
        $tasks = AgentTask::query()->whereIn('agent_id', $agentList->pluck('id'))->orderByDesc('id')->get()
            ->groupBy('agent_id')->map(fn ($rows) => $rows->take(self::RECENT_TASKS));

        return [
            'name' => 'منابع انسانی',
            'users_by_role' => (clone $users)->selectRaw('user_role, count(*) as n')->groupBy('user_role')->pluck('n', 'user_role')->all(),
            'active_users' => (clone $users)->where('active', true)->count(),
            'users' => self::section(
                (clone $users)->count(),
                (clone $users)->orderBy('id')->limit($cap['users'])->get(['id', 'full_name', 'user_role', 'active']),
                fn (AppUser $u) => [
                    'id' => $u->id, 'name' => $u->full_name, 'role' => $u->user_role, 'active' => (bool) $u->active,
                    'departments' => $memberships->get($u->id, collect())->map(fn (DepartmentMember $m) => [
                        'name' => $m->department?->name, 'role' => $m->role->value ?? $m->role,
                    ])->values()->all(),
                ],
            ),
            'agents' => self::section(
                (clone $agents)->count(),
                $agentList,
                fn (AppUser $a) => [
                    'id' => $a->id, 'name' => $a->full_name, 'active' => (bool) $a->active,
                    'job_description' => (string) $a->agent_instructions,
                    'departments_it_reads' => $a->agentScope(), 'created_by' => $a->agent_created_by,
                    'created_at' => $a->created_at?->toDateString(),
                    'recent_tasks' => ($tasks->get($a->id) ?? collect())->map(fn (AgentTask $t) => [
                        'instruction' => mb_substr((string) $t->instruction, 0, 300),
                        'status' => $t->status,
                        'result' => $t->result === null ? null : mb_substr((string) $t->result, 0, 600),
                        'error' => $t->error,
                        'finished_at' => $t->finished_at?->format('Y-m-d H:i'),
                    ])->values()->all(),
                ],
            ),
        ];
    }

    private const RECENT_TASKS = 5;

    /** A department of its own: members, the resources it keeps up to date (and which are stale), its suggestions. */
    private function general(Department $d, CarbonImmutable $now): array
    {
        $cap = (array) config('aiso.agent.briefing');
        $members = DepartmentMember::query()->where('department_id', $d->id)->with('user:id,full_name,user_role')->get();
        $resources = WorkResource::query()->where('department_id', $d->id)->orderBy('sort_order')->orderBy('id');
        $suggestions = DepartmentSuggestion::query()->where('department_id', $d->id);

        return [
            'name' => $d->name,
            'description' => $d->description,
            'status' => $d->status,
            'members' => self::section(
                $members->count(),
                $members->take($cap['members']),
                fn (DepartmentMember $m) => ['id' => $m->user_id, 'name' => $m->user?->full_name, 'role' => $m->role->value ?? $m->role],
            ),
            'resources' => self::section(
                (clone $resources)->count(),
                (clone $resources)->limit($cap['resources'])->get(),
                fn (WorkResource $r) => ['id' => $r->id, 'name' => $r->name, 'status' => $r->status, 'capacity' => (int) $r->capacity,
                    'reviewed_at' => $r->reviewed_at?->toDateString(), 'stale' => $this->departments->isStale($r, $now)],
            ),
            'suggestions' => [
                'by_status' => (clone $suggestions)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')->all(),
                'latest' => self::section(
                    (clone $suggestions)->count(),
                    (clone $suggestions)->orderByDesc('id')->limit($cap['suggestions'])->get(['id', 'title', 'status', 'created_at']),
                    fn (DepartmentSuggestion $s) => ['id' => $s->id, 'title' => $s->title, 'status' => $s->status->value ?? $s->status,
                        'created_at' => $s->created_at?->toDateString()],
                ),
            ],
        ];
    }

    /**
     * One bounded list: how many rows exist, how many are shown, and the shown rows.
     *
     * @return array{total: int, shown: int, truncated: bool, rows: array}
     */
    private static function section(int $total, iterable $rows, callable $map): array
    {
        $shown = [];
        foreach ($rows as $row) {
            $shown[] = $map($row);
        }

        return ['total' => $total, 'shown' => count($shown), 'truncated' => $total > count($shown), 'rows' => $shown];
    }
}
