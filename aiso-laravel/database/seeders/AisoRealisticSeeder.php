<?php

namespace Database\Seeders;

use App\Enums\DepartmentRole;
use App\Enums\OperationStatus;
use App\Enums\ProjectStatus;
use App\Models\AppUser;
use App\Models\Department;
use App\Models\Operation;
use App\Models\Project;
use App\Services\AgentService;
use App\Services\DepartmentService;
use App\Services\OperationService;
use App\Services\PlanningService;
use App\Services\ProjectService;
use App\Services\UserService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A realistic development dataset: people, departments, resources, three active projects and one archived project, about
 * seventy operations with chained and parallel prerequisites, work in every state, reports, and two AI agents.
 *
 * Everything goes through the same services the screens use (users, departments, the plan builder, the operation
 * lifecycle, the agents), so the rules and the audit trail apply. Only the timestamps of past work are moved back in time
 * afterwards (the services stamp the current time); that is the one thing done directly, and it is listed in the report.
 *
 * Runs only with AISO_SEED_REALISTIC=true, and only once: it stops when the project LINE-A already exists.
 *   AISO_SEED_REALISTIC=true php artisan db:seed --class=AisoRealisticSeeder
 */
class AisoRealisticSeeder extends Seeder
{
    /** The real project ids are fictional and short: LINE-A, LINE-B, LINE-C (active) and LINE-D (archived). */
    private const PROJECTS = [
        'LINE-A' => ['name' => 'خط تولید شمال', 'due' => 60, 'ops' => 25, 'complete' => 8, 'open' => 5],
        'LINE-B' => ['name' => 'خط تولید جنوب', 'due' => -5, 'ops' => 20, 'complete' => 6, 'open' => 4],
        'LINE-C' => ['name' => 'خط بسته‌بندی', 'due' => 30, 'ops' => 18, 'complete' => 4, 'open' => 4],
        'LINE-D' => ['name' => 'خط آزمایشی قدیمی', 'due' => -40, 'ops' => 4, 'complete' => 4, 'open' => 0],
    ];

    /** Fictional people. Accounts get a random temporary password that is never printed; the owner can reset it. */
    private const PEOPLE = [
        ['user4', 'نگار صادقی', 'USER_1'],
        ['user5', 'پیمان رستمی', 'USER_2'],
        ['user6', 'سحر احمدی', 'USER_3'],
        ['user7', 'کیوان مرادی', 'USER_1'],
    ];

    public function run(): void
    {
        if (!config('aiso.seed.realistic')) {
            $this->command?->warn('AISO_SEED_REALISTIC is not true: nothing was created.');

            return;
        }
        if (Project::query()->whereKey('LINE-A')->exists()) {
            $this->command?->info('The realistic data is already loaded (LINE-A exists): nothing was created.');

            return;
        }
        $owner = AppUser::query()->findOrFail('owner');
        $report = [];

        $report['users'] = $this->people($owner);
        $report['departments'] = $this->departments($owner);
        $report['resources'] = $this->resources($owner);
        $report['projects'] = $this->projects($owner);
        $report['operations'] = $this->states($owner);
        // a project can only be archived once its operations are finished or cancelled: LINE-D is completed first
        app(ProjectService::class)->update('LINE-D', null, null, false, ProjectStatus::ARCHIVED, $owner);
        $report['archived'] = 'LINE-D';
        $report['agents'] = $this->agents($owner);

        foreach ($report as $section => $counts) {
            $this->command?->info(str_pad($section, 12) . ' ' . json_encode($counts, JSON_UNESCAPED_UNICODE));
        }
    }

    /** Creates the accounts that do not exist yet, and deactivates one of them. */
    private function people(AppUser $owner): array
    {
        $created = 0;
        foreach (self::PEOPLE as [$id, $name, $role]) {
            if (AppUser::query()->whereKey($id)->exists()) {
                continue;
            }
            app(UserService::class)->create($id, $name, $role, null, null, Str::random(20), $owner);
            $created++;
        }
        app(UserService::class)->update('user7', null, null, false, null, null, $owner);

        return ['created' => $created, 'inactive' => ['user7']];
    }

    /** Departments with members: production, human resources and research and development. */
    private function departments(AppUser $owner): array
    {
        $svc = app(DepartmentService::class);
        $research = Department::query()->where('name', 'تحقیق و توسعه')->first();
        if ($research === null) {
            $research = Department::query()->findOrFail($svc->create('تحقیق و توسعه', 'بررسی روش‌های تازه و نمونه‌سازی', $owner)['id']);
        }
        $production = Department::query()->where('code', Department::PRODUCTION)->firstOrFail();
        $hr = Department::query()->where('code', Department::HR)->firstOrFail();

        $svc->setMembers($production->id, $this->members([
            ['manager', 'HEAD'], ['user1', 'MEMBER'], ['user2', 'MEMBER'], ['user3', 'MEMBER'], ['user4', 'MEMBER'], ['user5', 'MEMBER'], ['user6', 'MEMBER'],
        ]), $owner);
        // user7 is the inactive account: an inactive person cannot be a member, so HR does not list them
        $svc->setMembers($hr->id, $this->members([['user2', 'HEAD'], ['user1', 'MEMBER']]), $owner);
        $svc->setMembers($research->id, $this->members([['user4', 'HEAD'], ['user6', 'MEMBER']]), $owner);

        return ['departments' => 3, 'research_id' => $research->id];
    }

    /** @param array<int, array{0: string, 1: string}> $pairs */
    private function members(array $pairs): array
    {
        return array_map(static fn (array $m) => ['userId' => $m[0], 'role' => DepartmentRole::from($m[1])], $pairs);
    }

    /** Ten resources: capacities one, two and three; two of them inactive; each with a responsible person. */
    private function resources(AppUser $owner): array
    {
        return ['count' => count($this->resourceRows()), 'inactive' => 2];
    }

    /** @return array<int, array<string, mixed>> */
    private function resourceRows(): array
    {
        $names = ['برش لیزری', 'خم‌کاری', 'جوشکاری نقطه‌ای', 'سنباده‌زنی', 'رنگ‌پاشی', 'خشک‌کن', 'بسته‌بندی دستی', 'بسته‌بندی ماشینی', 'کنترل کیفیت', 'انبارگردانی'];
        $capacity = [1, 2, 1, 3, 2, 1, 2, 1, 3, 1];
        $rows = [];
        foreach ($names as $i => $name) {
            $rows[] = [
                'id' => sprintf('R-L%02d', $i + 1), 'name' => $name, 'type' => null,
                'userId' => ['user1', 'user2', 'user3', 'user4', 'user5', 'user6'][$i % 6],
                'capacity' => $capacity[$i], 'status' => $i >= 8 ? 'INACTIVE' : 'ACTIVE', 'description' => null,
            ];
        }

        return $rows;
    }

    /**
     * Creates the four projects through the plan builder's path, then gives the three active ones their priorities. The
     * archived one is archived through the project service.
     */
    private function projects(AppUser $owner): array
    {
        $plan = app(PlanningService::class);
        $resources = $this->resourceRows();
        $activeResources = array_values(array_filter($resources, fn (array $r) => $r['status'] === 'ACTIVE'));

        foreach (self::PROJECTS as $id => $spec) {
            $ops = $this->operationRows($id, $spec['ops'], $activeResources);
            $draft = [
                'newProjectName' => $spec['name'], 'newProjectId' => $id,
                'dueDate' => CarbonImmutable::now()->addDays($spec['due'])->format('Y-m-d'),
                'resources' => $resources, 'operations' => $ops['operations'], 'predecessors' => $ops['predecessors'],
            ];
            $result = $plan->submit($draft, true, $owner);
            if (($result['status'] ?? null) !== 'APPLIED') {
                throw new \RuntimeException("{$id} was not saved: " . json_encode($result['errors'] ?? [], JSON_UNESCAPED_UNICODE));
            }
        }

        // the three active projects: LINE-A first, LINE-B second, LINE-C third; the existing projects follow
        $existing = Project::query()->where('status', ProjectStatus::ACTIVE->value)
            ->whereNotIn('id', ['LINE-A', 'LINE-B', 'LINE-C'])->orderBy('priority')->orderBy('id')->pluck('id')->all();
        app(ProjectService::class)->setPriorities(array_merge(['LINE-A', 'LINE-B', 'LINE-C'], $existing), null, $owner);

        return ['created' => count(self::PROJECTS), 'active' => 3, 'operations' => array_sum(array_column(self::PROJECTS, 'ops'))];
    }

    /**
     * The operations of one project, in order. Each one depends on the one before it, and sometimes on the one three
     * before (a join); every fourth operation starts a new parallel branch.
     *
     * @return array{operations: array<int, array<string, mixed>>, predecessors: array<int, array<string, mixed>>}
     */
    private function operationRows(string $project, int $count, array $resources): array
    {
        $ops = [];
        $preds = [];
        $steps = ['برش', 'خم‌کاری', 'جوشکاری', 'سنباده', 'رنگ', 'خشک‌کردن', 'بسته‌بندی', 'کنترل'];
        for ($i = 0; $i < $count; $i++) {
            $id = sprintf('%s-%02d', str_replace('LINE-', '', $project), $i + 1);
            $ops[] = [
                'id' => $id, 'name' => $steps[$i % count($steps)] . ' ' . ($i + 1) . ' - ' . self::PROJECTS[$project]['name'],
                'resourceId' => $resources[$i % count($resources)]['id'], 'userId' => null,
                'prep' => [0, 0.5, 0.25][$i % 3], 'transport' => [0.25, 0, 0.5][$i % 3], 'setup' => [1, 0.5, 0][$i % 3],
                'direct' => [3, 2, 4, 1.5][$i % 4], 'description' => null,
            ];
            if ($i % 4 !== 0) {
                $preds[] = ['operationId' => $id, 'predecessorId' => sprintf('%s-%02d', str_replace('LINE-', '', $project), $i), 'type' => 'FINISH_TO_START', 'mandatory' => true];
            }
            if ($i >= 3 && $i % 7 === 0) {
                $preds[] = ['operationId' => $id, 'predecessorId' => sprintf('%s-%02d', str_replace('LINE-', '', $project), $i - 2), 'type' => 'FINISH_TO_START', 'mandatory' => true];
            }
        }

        return ['operations' => $ops, 'predecessors' => $preds];
    }

    /**
     * Brings operations to every state, in order: completed (with reports), in progress with progress, assigned, blocked,
     * cancelled. Each step is the real command, by the person who would do it.
     */
    private function states(AppUser $owner): array
    {
        $ops = app(OperationService::class);
        $manager = AppUser::query()->findOrFail('manager');
        $executors = ['user1', 'user2', 'user3', 'user4', 'user5', 'user6'];
        $done = [];
        $counts = array_fill_keys(['COMPLETED', 'IN_PROGRESS', 'ASSIGNED', 'BLOCKED', 'CANCELLED', 'READY'], 0);
        $touched = [];

        foreach (self::PROJECTS as $project => $spec) {
            $list = Operation::query()->where('project_id', $project)->orderBy('id')->get();
            // completed: the first operations in order (their prerequisites are earlier, so they are always ready)
            foreach ($list->take($spec['complete']) as $k => $op) {
                $who = $executors[$k % count($executors)];
                $this->assignedRun($ops, $op->id, $who, $manager, $touched);
                $ops->start($op->id, $this->user($who));
                $ops->progress($op->id, 50, 'حدود نیمی از کار انجام شد', $this->user($who));
                $ops->complete($op->id, 'کار کامل شد و کنترل شد', $this->user($who));
                $done[$op->id] = true;
                $counts['COMPLETED']++;
            }
            // the rest of the states go on operations whose prerequisites are all completed now
            $ready = $list->filter(fn (Operation $o) => !isset($done[$o->id]) && $this->predecessorsDone($o, $done))->values();
            $i = 0;
            foreach ($ready->take($spec['open']) as $op) {
                // the last of them is left alone: its prerequisites are done, so it is READY and waiting for an assignment
                if ($i === $spec['open'] - 1) {
                    $counts['READY'] = ($counts['READY'] ?? 0) + 1;
                    break;
                }
                $who = $executors[($i + 2) % count($executors)];
                $this->assignedRun($ops, $op->id, $who, $manager, $touched);
                if ($i < 3) {
                    $ops->start($op->id, $this->user($who));
                    $ops->progress($op->id, [20, 45, 70][$i], 'در حال انجام', $this->user($who));
                    $counts['IN_PROGRESS']++;
                } elseif ($i === 3) {
                    $ops->block($op->id, 'منتظر رسیدن قطعهٔ جایگزین از انبار', $this->user($who));
                    $counts['BLOCKED']++;
                } else {
                    $counts['ASSIGNED']++;
                }
                $i++;
            }
        }

        // one cancelled operation: the last one of LINE-C has nothing after it, so cancelling it breaks no chain
        $last = Operation::query()->where('project_id', 'LINE-C')->orderByDesc('id')->firstOrFail();
        $ops->cancel($last->id, 'حذف از دامنهٔ این خط', $manager);
        $counts['CANCELLED']++;

        $this->backdate(array_keys($touched));

        return ['by_state' => $counts, 'backdated_operations' => count($touched)];
    }

    /** Assigns an operation (by the manager) and remembers it, so its timestamps can be moved back afterwards. */
    private function assignedRun(OperationService $ops, string $id, string $who, AppUser $manager, array &$touched): void
    {
        $ops->assign($id, $who, null, null, $manager->id, 'تخصیص اولیه');
        $touched[$id] = true;
    }

    private function user(string $id): AppUser
    {
        return AppUser::query()->findOrFail($id);
    }

    /** @param array<string, true> $done */
    private function predecessorsDone(Operation $o, array $done): bool
    {
        $preds = DB::table('predecessor')->where('operation_id', $o->id)->pluck('predecessor_id')->all();
        foreach ($preds as $p) {
            if (!isset($done[$p])) {
                return false;
            }
        }

        return true;
    }

    /**
     * The services stamp the current time on every change. Work that finished weeks ago is shown with its real order:
     * assigned first, then started, progressed and completed, spread over the last six weeks. Only these time columns move.
     *
     * @param string[] $ids
     */
    private function backdate(array $ids): void
    {
        $now = CarbonImmutable::now();
        foreach ($ids as $n => $id) {
            $done = Operation::query()->find($id);
            if ($done === null) {
                continue;
            }
            $weeksAgo = 1 + ($n % 6);
            $assigned = $now->subWeeks($weeksAgo)->subDays($n % 5);
            $started = $assigned->addDay();
            $completed = $started->addDays(2 + $n % 3);
            if ($done->status === OperationStatus::COMPLETED) {
                DB::table('operation')->where('id', $id)->update(['assigned_at' => $assigned, 'started_at' => $started, 'completed_at' => $completed]);
                DB::table('operation_report')->where('operation_id', $id)->orderBy('id')->get()->values()
                    ->each(function ($r, $k) use ($id, $started, $completed) {
                        $at = $k === 0 ? $started : $completed;
                        DB::table('operation_report')->where('id', $r->id)->update(['created_at' => $at]);
                    });
            } else {
                DB::table('operation')->where('id', $id)->update(['assigned_at' => $assigned, 'started_at' => $done->started_at ? $started : null]);
            }
        }
    }

    /** Three agents: two active with a job each, one inactive. Scoped to the departments they read. */
    private function agents(AppUser $owner): array
    {
        $svc = app(AgentService::class);
        $research = Department::query()->where('name', 'تحقیق و توسعه')->firstOrFail();
        $a = $svc->create($owner, 'ایجنت پیگیری تأخیرها', 'هر روز عملیات‌های دیرتر از موعد را فهرست کن، بزرگ‌ترین تأخیر را اول بیاور و علت انسداد هر کدام را در یک جمله بنویس.', ['production']);
        $b = $svc->create($owner, 'ایجنت گزارش منابع', 'وضعیت بار هر منبع را گزارش کن و منابع زیر بار و تداخل برنامه‌ریزی را جداگانه بگو. اسم کسی را در گزارش نیاور.', ['production', 'hr']);
        $c = $svc->create($owner, 'ایجنت پژوهش', 'گزارش ماهانهٔ پیشنهادهای تحقیق و توسعه را برای مدیر بنویس.', ['dept-' . $research->id]);
        $svc->update($owner, $c['id'], null, null, null, false);

        return ['created' => 3, 'active' => 2, 'inactive' => 1, 'ids' => [$a['id'], $b['id'], $c['id']]];
    }
}
