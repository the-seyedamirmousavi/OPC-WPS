<?php

namespace App\Imports;

use App\Enums\OperationStatus;
use App\Enums\ProjectStatus;
use App\Exceptions\ApiException;
use App\Models\AppUser;
use App\Models\ImportLog;
use App\Models\Item;
use App\Models\Operation;
use App\Models\Predecessor;
use App\Models\Project;
use App\Models\SystemSetting;
use App\Models\WorkResource;
use App\Services\ProjectData;
use App\Services\ProjectDataService;
use App\Services\SchedulingService;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Throwable;

/**
 * Validates and imports the Excel file (master template or simple layout).
 *
 * Nothing is written unless the whole file validates. Every problem is reported with sheet, row, column and a
 * description. Re-importing is an upsert by business id: operations already started keep their status, and structural
 * changes (resource, predecessors) to them are rejected.
 *
 * $target (array) keys: projectId, newProjectName, newProjectId, dueDate, ranking (see {@see resolveTarget()}).
 * Results are arrays shaped for the JSON API.
 */
class ImportService
{
    public const NEW = 'NEW';

    public function __construct(
        private readonly ProjectDataService $dataService,
        private readonly SchedulingService $scheduling,
        private readonly ImportValidator $validator,
        private readonly ImportApplier $applier,
    ) {
    }

    /**
     * @param string $path      the uploaded file on disk
     * @param bool   $apply     false = validate only (dry run); true = validate and, when clean, apply
     * @param array  $target    which project the file belongs to and, for a new project, the priority ranking
     */
    public function process(string $path, ?string $fileName, bool $apply, AppUser $actor, array $target = []): array
    {
        $data = $this->parseWorkbook($path);
        if ($data->format === 'UNKNOWN') {
            return $this->finish($data, 'REJECTED', $fileName, $actor, [], [], null);
        }

        return $this->processParsed($data, $fileName, $apply, $actor, $target);
    }

    /**
     * Reads a workbook into typed data without validating it against the database. A file that cannot be read or is not
     * one of the two layouts comes back with format UNKNOWN and one error.
     */
    public function parseWorkbook(string $path): ParsedData
    {
        try {
            $wb = IOFactory::load($path);
        } catch (Throwable $e) {
            $bad = new ParsedData();
            $bad->format = 'UNKNOWN';
            $bad->error('-', 0, '-', 'The file is not a readable Excel workbook: ' . $e->getMessage());

            return $bad;
        }

        if (SimpleParser::matches($wb)) {
            return SimpleParser::parse($wb);
        }
        if (SheetReader::has($wb, 'Resources') && SheetReader::has($wb, 'OPC')) {
            return MasterParser::parse($wb);
        }
        $bad = new ParsedData();
        $bad->format = 'UNKNOWN';
        $bad->error('-', 0, '-', 'Unrecognised workbook. Expected the master template (sheets Resources, OPC, ...) '
            . 'or the simple layout (sheets opc and source).');

        return $bad;
    }

    /**
     * Validates, and when clean and $apply is true writes, data that is already parsed. Files and the planning forms
     * (no Excel needed) take this same path, so every rule, the schedule preview and the audit trail are shared.
     */
    public function processParsed(ParsedData $data, ?string $fileName, bool $apply, AppUser $actor, array $target = []): array
    {
        $r = $this->resolveTarget($target, $data);
        $ranks = $r === null ? null : $this->resolveRanking($target, $r, $data);
        if ($data->errors === [] && $r !== null) {
            $this->validator->validate($data, $actor, $r);
        }
        if ($data->errors !== [] || $r === null) {
            return $this->finish($data, 'REJECTED', $fileName, $actor, [], [], $this->extras($r, null));
        }

        $needsPriority = $r['isNew'] && $r['others'] !== [] && $ranks === null;
        $impact = $this->impact($data, $r, $ranks ?? $this->defaultRanking($r));
        $ex = $this->extras($r, $impact);
        if ($needsPriority) {
            return $this->finish($data, 'PRIORITY_REQUIRED', $fileName, $actor, $this->summaryCounts($data), [], $ex);
        }
        if (!$apply) {
            return $this->finish($data, 'VALID', $fileName, $actor, $this->summaryCounts($data), [], $ex);
        }

        $created = [];
        $counts = $this->applier->apply($data, $actor, $created, $r, $ranks);

        return $this->finish($data, 'APPLIED', $fileName, $actor, $counts, $created, $ex);
    }

    /** Used by the demo-data endpoint: imports the bundled sample workbook. */
    public function processSample(bool $apply, AppUser $actor): array
    {
        $path = resource_path('samples/simple-sample.xlsx');
        if (!is_file($path)) {
            throw ApiException::notFound('Sample file simple-sample.xlsx not found');
        }

        return $this->process($path, 'simple-sample.xlsx', $apply, $actor);
    }

    // ---- target project & priorities ----------------------------------------------------------------------------

    /**
     * Resolves which project the file belongs to. Returns null (with an error recorded) when that is ambiguous.
     *
     * @return array{existing: ?Project, id: string, name: string, isNew: bool, dueDate: ?\DateTimeInterface, others: Project[]}|null
     */
    private function resolveTarget(array $t, ParsedData $d): ?array
    {
        $active = Project::query()->where('status', ProjectStatus::ACTIVE->value)
            ->orderBy('priority')->orderBy('id')->get()->all();
        $dueDate = $t['dueDate'] ?? null;

        if (!empty($t['projectId'])) {
            $p = Project::query()->find(trim($t['projectId']));
            if ($p === null) {
                $d->error('-', 0, 'projectId', "Project '{$t['projectId']}' does not exist");

                return null;
            }
            if ($p->status !== ProjectStatus::ACTIVE) {
                $d->error('-', 0, 'projectId', "Project '{$p->id}' is archived");

                return null;
            }

            return ['existing' => $p, 'id' => $p->id, 'name' => $p->name, 'isNew' => false, 'dueDate' => null,
                'others' => array_values(array_filter($active, static fn (Project $x) => $x->id !== $p->id))];
        }

        if (!empty($t['newProjectName'])) {
            $id = !empty($t['newProjectId']) ? trim($t['newProjectId']) : $this->uniqueProjectId('PRJ-' . (Project::query()->count() + 1));
            if (preg_match('/\A[A-Za-z0-9_.\-]{1,64}\z/', $id) !== 1) {
                $d->error('-', 0, 'newProjectId', 'Project id may only contain letters, digits, dot, dash and underscore');

                return null;
            }
            if (Project::query()->whereKey($id)->exists()) {
                $d->error('-', 0, 'newProjectId', "Project id '{$id}' already exists");

                return null;
            }

            return ['existing' => null, 'id' => $id, 'name' => trim($t['newProjectName']), 'isNew' => true,
                'dueDate' => $dueDate, 'others' => $active];
        }

        if (count($active) === 1) {
            $p = $active[0];

            return ['existing' => $p, 'id' => $p->id, 'name' => $p->name, 'isNew' => false, 'dueDate' => null, 'others' => []];
        }

        if ($active === []) {
            $s = SystemSetting::current();
            $base = empty($s->project_id) ? 'P-001' : $s->project_id;
            $id = $this->uniqueProjectId($base);
            $name = empty($s->project_name) ? $id : $s->project_name;

            return ['existing' => null, 'id' => $id, 'name' => $name, 'isNew' => true, 'dueDate' => $dueDate, 'others' => []];
        }

        $d->error('-', 0, 'projectId', 'Several projects are active. Choose the target project (projectId) or create a new one (newProjectName).');

        return null;
    }

    private function uniqueProjectId(string $base): string
    {
        $id = $base;
        for ($i = 2; Project::query()->whereKey($id)->exists(); $i++) {
            $id = $base . '-' . $i;
        }

        return $id;
    }

    /**
     * Validates the requested priority order. Returns project id => rank for all active projects including the new one,
     * or null when no ranking applies (existing project, first project, or none supplied yet).
     *
     * @return array<string, int>|null
     */
    private function resolveRanking(array $t, array $r, ParsedData $d): ?array
    {
        if (!$r['isNew']) {
            return null;
        }
        if ($r['others'] === []) {
            return [$r['id'] => 1];
        }
        if (empty($t['ranking'])) {
            return null;
        }
        $order = array_map(static fn (string $x) => $x === $r['id'] ? self::NEW : $x, $t['ranking']);
        $expected = array_map(static fn (Project $p) => $p->id, $r['others']);
        $expected[] = self::NEW;
        $seen = [];
        foreach ($order as $id) {
            if (isset($seen[$id]) || !in_array($id, $expected, true)) {
                $d->error('-', 0, 'ranking', "Ranking entry '{$id}' is duplicated or not an active project");

                return null;
            }
            $seen[$id] = true;
        }
        if (count($seen) !== count($expected)) {
            $d->error('-', 0, 'ranking', 'The ranking must list every active project and NEW exactly once');

            return null;
        }
        $ranks = [];
        foreach ($order as $i => $id) {
            $ranks[$id === self::NEW ? $r['id'] : $id] = $i + 1;
        }

        return $ranks;
    }

    /** Ranks used for the preview when none was given yet: others keep their order, a new project goes last. */
    private function defaultRanking(array $r): array
    {
        $ranks = [];
        $i = 1;
        foreach ($r['others'] as $p) {
            $ranks[$p->id] = $r['existing'] !== null ? (int) $p->priority : $i;
            $i++;
        }
        if ($r['existing'] !== null) {
            $ranks[$r['existing']->id] = (int) $r['existing']->priority;
        } else {
            $ranks[$r['id']] = $i;
        }

        return $ranks;
    }

    private function extras(?array $r, ?array $impact): ?array
    {
        if ($r === null) {
            return null;
        }

        return [
            'projectId' => $r['id'],
            'projectName' => $r['name'],
            'isNew' => $r['isNew'],
            'active' => array_map(static fn (Project $p) => ['id' => $p->id, 'name' => $p->name, 'priority' => (int) $p->priority], $r['others']),
            'impact' => $impact,
        ];
    }

    // ---- schedule impact (what-if, nothing is stored) -----------------------------------------------------------

    private function impact(ParsedData $d, array $r, array $ranks): ?array
    {
        try {
            $base = $this->dataService->load();
            $hypo = $this->hypothetical($base, $d, $r, $ranks);

            return $this->scheduling->impact($base, $hypo, $ranks);
        } catch (Throwable $e) {
            Log::warning('Could not compute schedule impact: ' . $e->getMessage());

            return null;
        }
    }

    /** The project data as it would be after the import, built in memory (nothing is saved). */
    private function hypothetical(ProjectData $base, ParsedData $d, array $r, array $ranks): ProjectData
    {
        $projs = [];
        foreach ($base->projects as $id => $p) {
            $projs[$id] = self::copyProject($p);
        }
        if ($r['isNew']) {
            $projs[$r['id']] = new Project(['id' => $r['id'], 'name' => $r['name'], 'due_date' => $r['dueDate'],
                'priority' => 1, 'status' => ProjectStatus::ACTIVE]);
        }
        foreach ($projs as $p) {
            if (isset($ranks[$p->id])) {
                $p->priority = $ranks[$p->id];
            }
        }

        $res = $base->resources;
        foreach ($d->resources as $rr) {
            $res[$rr['id']] = new WorkResource(['id' => $rr['id'], 'name' => $rr['name'], 'capacity' => $rr['capacity'],
                'status' => $rr['status']]);
        }

        $ops = $base->ops;
        foreach ($d->ops as $o) {
            $old = $base->ops[$o['id']] ?? null;
            $ops[$o['id']] = new Operation([
                'id' => $o['id'],
                'project_id' => $r['id'],
                'name' => $o['name'],
                'resource_id' => $o['resourceId'],
                'preparation_time' => $o['prep'],
                'transport_time' => $o['transport'],
                'setup_time' => $o['setup'],
                'direct_time' => $o['direct'],
                'status' => $old?->status ?? ($o['status'] !== null ? OperationStatus::from($o['status']) : OperationStatus::NOT_READY),
                'progress_percent' => $old?->progress_percent ?? 0,
            ]);
        }

        $replaced = [];
        if ($d->hasPredecessorSheet) {
            foreach ($d->ops as $o) {
                $replaced[$o['id']] = true;
            }
            foreach ($d->preds as $p) {
                $replaced[$p['operationId']] = true;
            }
        }
        $all = [];
        foreach ($base->predecessors as $list) {
            foreach ($list as $p) {
                if (!isset($replaced[$p->operation_id])) {
                    $all[] = $p;
                }
            }
        }
        foreach ($d->preds as $pr) {
            $all[] = new Predecessor([
                'operation_id' => $pr['operationId'],
                'predecessor_id' => $pr['predecessorId'],
                'dependency_type' => $pr['type'],
                'mandatory' => $pr['mandatory'],
            ]);
        }
        $predsByOp = [];
        $succ = [];
        foreach ($all as $p) {
            $predsByOp[$p->operation_id][] = $p;
            $succ[$p->predecessor_id][] = $p->operation_id;
        }

        return new ProjectData($base->settings, $projs, $ops, $res, $base->users, $base->items, $predsByOp, $succ,
            $base->forces, $base->baseline, $base->now);
    }

    private static function copyProject(Project $p): Project
    {
        return new Project(['id' => $p->id, 'name' => $p->name, 'priority' => (int) $p->priority,
            'due_date' => $p->due_date, 'status' => $p->status]);
    }

    /** @return array<int, array{entity: string, created: int, updated: int}> */
    private function summaryCounts(ParsedData $d): array
    {
        return [
            ['entity' => 'users', 'created' => count($d->users), 'updated' => 0],
            ['entity' => 'resources', 'created' => count($d->resources), 'updated' => 0],
            ['entity' => 'items', 'created' => count($d->items), 'updated' => 0],
            ['entity' => 'operations', 'created' => count($d->ops), 'updated' => 0],
            ['entity' => 'predecessors', 'created' => count($d->preds), 'updated' => 0],
        ];
    }

    // ---- result & history ---------------------------------------------------------------------------------------

    private function finish(ParsedData $d, string $status, ?string $fileName, AppUser $actor, array $counts,
                            array $newUsers, ?array $ex): array
    {
        try {
            $details = implode("\n", array_map(
                static fn (array $e) => $e['sheet'] . ' row ' . $e['row'] . ' [' . ($e['column'] ?? '-') . ']: ' . $e['message'],
                array_slice($d->errors, 0, 100)));
            ImportLog::query()->create([
                'imported_at' => now(),
                'actor_id' => $actor->id,
                'file_name' => $fileName,
                'file_format' => $d->format,
                'status' => $status,
                'error_count' => count($d->errors),
                'summary' => $status . ($ex === null ? '' : ' [' . $ex['projectId'] . ']') . ' - ' . count($d->errors)
                    . ' error(s), ' . count($d->warnings) . ' warning(s)',
                'details' => mb_substr($details, 0, 9000),
            ]);
        } catch (Throwable $e) {
            Log::warning('Could not write import log: ' . $e->getMessage());
        }

        return [
            'status' => $status,
            'format' => $d->format,
            'errors' => $d->errors,
            'warnings' => $d->warnings,
            'counts' => $counts,
            'newUsers' => $newUsers,
            'projectId' => $ex['projectId'] ?? null,
            'projectName' => $ex['projectName'] ?? null,
            'newProject' => $ex['isNew'] ?? false,
            'activeProjects' => $ex['active'] ?? [],
            'impact' => $ex['impact'] ?? null,
        ];
    }
}
