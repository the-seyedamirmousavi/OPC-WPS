<?php

namespace App\AI\Departments;

use App\AI\Semantic\TextSimilarity;
use App\Enums\ProjectStatus;
use App\Models\Operation;
use App\Models\Project;
use App\Models\WorkResource;

/**
 * Sensible defaults for a new operation when the request leaves details out, chosen from the organisation's own data so
 * the assistant can act instead of asking:
 *  - project: the one named if it exists, otherwise the highest-priority active project;
 *  - resource: the active resource whose name and type best fit the operation's name; when none fits, the active resource
 *    with the fewest open planned hours;
 *  - hours: 1 hour of direct time.
 * Each choice is explained in `chosen`, shown with the proposal before the user confirms it.
 */
final class OperationDefaults
{
    /** A fit below this similarity is not trusted; the least-loaded resource is used instead. */
    private const FIT_MIN = 0.25;

    /** @return array{args: array<string, mixed>, chosen: array<string, string>} */
    public static function fill(array $args): array
    {
        $chosen = [];
        if (!empty($args['project_id']) && !Project::query()->whereKey($args['project_id'])->where('status', ProjectStatus::ACTIVE->value)->exists()) {
            $chosen['project_id'] = "«{$args['project_id']}» is not an active project";
            unset($args['project_id']);
        }
        if (empty($args['project_id'])) {
            $p = Project::query()->where('status', ProjectStatus::ACTIVE->value)->orderBy('priority')->orderBy('id')->first();
            if ($p !== null) {
                $args['project_id'] = $p->id;
                $chosen['project_id'] = trim(($chosen['project_id'] ?? '') . " the highest-priority active project: {$p->name}");
            }
        }

        if (empty($args['resource_id']) || WorkResource::query()->whereKey($args['resource_id'])->value('status') !== 'ACTIVE') {
            $given = $args['resource_id'] ?? null;
            [$id, $why] = self::resourceFor((string) ($args['name'] ?? ''));
            if ($id !== null) {
                $args['resource_id'] = $id;
                $chosen['resource_id'] = ($given ? "«{$given}» is not an active resource; " : '') . $why;
            }
        }

        if (!isset($args['direct_hours']) || !is_numeric($args['direct_hours']) || (float) $args['direct_hours'] <= 0) {
            $args['direct_hours'] = 1;
            $chosen['direct_hours'] = 'no duration was given: 1 hour';
        }

        return ['args' => $args, 'chosen' => $chosen];
    }

    /** @return array{0: ?string, 1: string} the resource and why it was chosen */
    private static function resourceFor(string $operationName): array
    {
        $resources = WorkResource::query()->where('status', 'ACTIVE')->orderBy('sort_order')->orderBy('id')->get();
        if ($resources->isEmpty()) {
            return [null, 'there is no active resource'];
        }
        if (trim($operationName) !== '') {
            $docs = [];
            foreach ($resources as $r) {
                $docs[$r->id] = trim("{$r->name} {$r->resource_type} {$r->description}");
            }
            $ranked = TextSimilarity::rank($operationName, $docs, 1);
            if ($ranked !== [] && $ranked[0][1] >= self::FIT_MIN) {
                $r = $resources->firstWhere('id', $ranked[0][0]);

                return [$r->id, "the resource that fits the work best: {$r->name}"];
            }
        }
        $open = Operation::query()->whereIn('status', ['NOT_READY', 'READY', 'ASSIGNED', 'IN_PROGRESS', 'BLOCKED'])->get()
            ->groupBy('resource_id')->map(fn ($ops) => $ops->sum(fn (Operation $o) => $o->totalHours()));
        $best = $resources->sortBy(fn (WorkResource $r) => [(float) ($open[$r->id] ?? 0) / max(1, (int) $r->capacity), $r->id])->first();

        return [$best->id, "no resource is meant for this kind of work; the least-loaded active resource: {$best->name}"];
    }
}
