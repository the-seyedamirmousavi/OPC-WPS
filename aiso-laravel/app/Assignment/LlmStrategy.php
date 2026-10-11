<?php

namespace App\Assignment;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Asks the configured LLM to dispatch the READY operations and returns a structured answer.
 *
 * The model only proposes. Every pick is re-checked against the same hard constraints the algorithm uses
 * ({@see AssignmentState}); violating picks are dropped and reported as warnings. The API key comes from the
 * environment (ANTHROPIC_API_KEY) and is never stored in the database.
 */
final class LlmStrategy implements AssignmentStrategy
{
    public const SYSTEM_PROMPT = <<<'PROMPT'
You are the dispatcher of a small manufacturing project. You receive a JSON snapshot with the operations that are READY
to start, the executive users, and the resources (work centres) with their capacity. Decide which user should perform
which READY operation.

HARD CONSTRAINTS - the system rejects any assignment that breaks one of them:
1. Use only user ids and operation ids that appear in the snapshot.
2. Each operation is assigned to at most one user.
3. If an operation has fixedUserId, it can only go to that user. When forcedByManager is true the manager forced that
   user: assign it even if the user has no freeTaskSlots left.
4. A resource can take at most freeSlots new operations (count your own assignments too).
5. A user can take at most freeTaskSlots new operations (count your own assignments too).

PROJECTS: several projects share the same resources. Every operation has a projectPriority (1 = most important project).
The system enforces strict priority: an operation of a lower-priority project may only take a resource slot that no
READY operation of a higher-priority project on the same resource is waiting for. Any pick that violates this is moved
or dropped by the system.

OBJECTIVES, in this order:
1. Respect project priority (see above).
2. Waste no resource time: never leave a free resource slot idle if a READY operation for it exists and a user can take
   it. Lower-priority operations may fill slots that higher-priority work does not need.
3. Finish each project as early as possible: give priority to operations with a larger tailHours (the longest chain of
   work waiting behind them is the critical path) and an earlier plannedStartHours (smaller = sooner).
4. Balance workload: prefer the user with the lower loadHours.
5. When loads are comparable, prefer the resource's responsibleUserId.

Every READY operation must appear exactly once, either in "assignments" or in "unassigned" with the reason it cannot be
started now. Keep each reason to one short plain-English sentence.

OUTPUT: answer with one JSON object only, no prose around it, in this shape:
{"assignments":[{"operationId":"...","userId":"...","reason":"..."}],"unassigned":[{"operationId":"...","reason":"..."}],"summary":"..."}
PROMPT;

    public function mode(): string
    {
        return 'LLM';
    }

    public function suggest(AssignmentContext $ctx): Suggestion
    {
        $cfg = config('aiso.llm');
        if (empty($cfg['api_key'])) {
            throw new LlmUnavailableException('ANTHROPIC_API_KEY is not configured on the server');
        }
        if (empty($cfg['model'])) {
            throw new LlmUnavailableException('AISO_LLM_MODEL is not set on the server');
        }
        if ($ctx->ready === []) {
            return Suggestion::of([], [], 'No READY operations.');
        }

        try {
            $response = Http::withHeaders([
                'x-api-key' => $cfg['api_key'],
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ])->timeout((int) $cfg['timeout_seconds'])->post(rtrim($cfg['base_url'], '/') . '/v1/messages', [
                'model' => $cfg['model'],
                'max_tokens' => (int) $cfg['max_tokens'],
                'system' => self::SYSTEM_PROMPT,
                'messages' => [['role' => 'user', 'content' => self::snapshot($ctx)]],
            ]);
        } catch (\Throwable $e) {
            Log::warning('LLM assignment call failed: ' . $e->getMessage());
            throw new LlmUnavailableException('LLM API call failed: ' . $e->getMessage(), $e);
        }
        if (!$response->successful()) {
            Log::warning('LLM assignment call failed: HTTP ' . $response->status());
            throw new LlmUnavailableException('LLM API call failed: HTTP ' . $response->status());
        }

        $body = $response->json();
        if (($body['stop_reason'] ?? null) === 'refusal') {
            throw new LlmUnavailableException('The model declined the request (stop reason: refusal)');
        }
        $text = '';
        foreach ($body['content'] ?? [] as $block) {
            if (($block['type'] ?? null) === 'text') {
                $text .= $block['text'] ?? '';
            }
        }
        $out = self::parseJson($text);
        if ($out === null) {
            throw new LlmUnavailableException('The model returned no structured output');
        }

        return self::validate($ctx, $out, $cfg['model'], $body['usage']['input_tokens'] ?? null,
            $body['usage']['output_tokens'] ?? null);
    }

    /** The first JSON object in the text, or null. */
    private static function parseJson(string $text): ?array
    {
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start === false || $end === false || $end < $start) {
            return null;
        }
        $data = json_decode(substr($text, $start, $end - $start + 1), true);

        return is_array($data) ? $data : null;
    }

    /**
     * Re-checks the model output against the hard constraints; anything that violates them is dropped.
     *
     * @param array{assignments?: array, unassigned?: array, summary?: string} $out
     */
    public static function validate(AssignmentContext $ctx, array $out, string $model, ?int $in, ?int $outTokens): Suggestion
    {
        $state = new AssignmentState($ctx);
        $picks = [];
        $warnings = [];
        $notes = [];
        $placed = [];
        $readyById = [];
        foreach ($ctx->ready as $o) {
            $readyById[$o->id] = $o;
        }

        // higher-priority projects claim capacity first, so lower-priority picks are the ones that no longer fit
        $proposed = is_array($out['assignments'] ?? null) ? $out['assignments'] : [];
        usort($proposed, static function (array $a, array $b) use ($readyById): int {
            $ra = $readyById[$a['operationId'] ?? ''] ?? null;
            $rb = $readyById[$b['operationId'] ?? ''] ?? null;

            return ($ra?->projectRank ?? PHP_INT_MAX) <=> ($rb?->projectRank ?? PHP_INT_MAX)
                ?: ($ra?->plannedStartHours ?? 0.0) <=> ($rb?->plannedStartHours ?? 0.0);
        });
        foreach ($proposed as $a) {
            $opId = $a['operationId'] ?? null;
            $userId = $a['userId'] ?? null;
            if (!is_string($opId) || !is_string($userId)) {
                continue;
            }
            if (isset($placed[$opId])) {
                $warnings[] = "Ignored duplicate assignment of {$opId}";
                continue;
            }
            $bad = $state->reject($opId, $userId);
            if ($bad !== null) {
                $warnings[] = "Dropped {$opId} -> {$userId}: {$bad}";
                $notes[$opId] = "Model suggestion rejected: {$bad}";
                continue;
            }
            $picks[] = new Pick($opId, $userId, self::blankTo($a['reason'] ?? null, 'Chosen by the model'));
            $state->apply($opId, $userId);
            $placed[$opId] = true;
        }

        self::repairPriorityInversions($ctx, $readyById, $picks, $placed, $warnings);

        foreach (is_array($out['unassigned'] ?? null) ? $out['unassigned'] : [] as $s) {
            $opId = $s['operationId'] ?? null;
            if (is_string($opId) && !isset($placed[$opId])) {
                $notes[$opId] ??= self::blankTo($s['reason'] ?? null, 'Left unassigned by the model');
            }
        }

        $skips = [];
        foreach ($ctx->ready as $op) {
            if (!isset($placed[$op->id])) {
                $skips[] = new Skip($op->id, $notes[$op->id] ?? 'Not addressed by the model');
            }
        }
        $summary = self::blankTo($out['summary'] ?? null, count($picks) . ' operations proposed by the model.');

        return new Suggestion($picks, $skips, $summary, $warnings, $model, $in, $outTokens);
    }

    /**
     * Strict project priority: if a better-ranked READY operation was left out while a worse-ranked one holds a slot on
     * the same resource, the worse-ranked pick hands its slot (and user) to the better-ranked operation, provided the
     * whole result still satisfies every hard constraint.
     *
     * @param Pick[] $picks
     * @param array<string, true> $placed
     * @param string[] $warnings
     */
    private static function repairPriorityInversions(AssignmentContext $ctx, array $readyById, array &$picks,
                                                     array &$placed, array &$warnings): void
    {
        $changed = true;
        for ($guard = 0; $changed && $guard < 200; $guard++) {
            $changed = false;
            $left = array_values(array_filter($ctx->ready, static fn (ReadyOp $o) => !isset($placed[$o->id])));
            usort($left, static fn (ReadyOp $a, ReadyOp $b): int => ($a->projectRank <=> $b->projectRank)
                ?: ($a->plannedStartHours <=> $b->plannedStartHours));
            foreach ($left as $y) {
                foreach ($picks as $z) {
                    $zo = $readyById[$z->operationId] ?? null;
                    if ($zo === null || $zo->resourceId !== $y->resourceId || $zo->projectRank <= $y->projectRank) {
                        continue;
                    }
                    if ($y->fixedUserId !== null && $y->fixedUserId !== $z->userId) {
                        continue;
                    }
                    $trial = array_values(array_filter($picks, static fn (Pick $p) => $p !== $z));
                    $trial[] = new Pick($y->id, $z->userId, 'Given this slot by the project-priority rule');
                    if (self::replay($ctx, $trial)) {
                        $picks = $trial;
                        unset($placed[$z->operationId]);
                        $placed[$y->id] = true;
                        $warnings[] = "Priority rule: {$y->id} (project priority {$y->projectRank}) took the slot of "
                            . "{$z->operationId} (project priority {$zo->projectRank}) on {$y->resourceName}";
                        $changed = true;
                        break;
                    }
                }
                if ($changed) {
                    break;
                }
            }
        }
    }

    /** @param Pick[] $picks */
    private static function replay(AssignmentContext $ctx, array $picks): bool
    {
        $state = new AssignmentState($ctx);
        foreach ($picks as $p) {
            if ($state->reject($p->operationId, $p->userId) !== null) {
                return false;
            }
            $state->apply($p->operationId, $p->userId);
        }

        return true;
    }

    private static function blankTo(mixed $s, string $fallback): string
    {
        return is_string($s) && trim($s) !== '' ? trim($s) : $fallback;
    }

    /** Only ids, names and numbers leave the system - no contact details or credentials. */
    public static function snapshot(AssignmentContext $ctx): string
    {
        $state = new AssignmentState($ctx);
        $root = [
            'maxActiveTasksPerUser' => $ctx->maxActiveTasksPerUser,
            'readyOperations' => array_map(static fn (ReadyOp $o) => [
                'id' => $o->id,
                'name' => $o->name,
                'resourceId' => $o->resourceId,
                'hours' => round($o->hours, 1),
                'tailHours' => round($o->tailHours, 1),
                'projectId' => $o->projectId,
                'projectName' => $o->projectName,
                'projectPriority' => $o->projectRank,
                'plannedStartHours' => round($o->plannedStartHours, 1),
                'fixedUserId' => $o->fixedUserId,
                'forcedByManager' => $o->forcedUser,
            ], $ctx->ready),
            'users' => array_map(static fn (UserInfo $u) => [
                'id' => $u->id,
                'name' => $u->fullName,
                'active' => $u->active,
                'activeTasks' => $u->activeTasks,
                'freeTaskSlots' => max(0, $ctx->maxActiveTasksPerUser - $u->activeTasks),
                'loadHours' => round($u->loadHours, 1),
            ], $ctx->users),
            'resources' => array_map(static fn (ResourceInfo $r) => [
                'id' => $r->id,
                'name' => $r->name,
                'capacity' => $r->capacity,
                'busy' => $r->used,
                'freeSlots' => max(0, $state->freeSlots($r->id)),
                'responsibleUserId' => $r->responsibleUserId,
                'active' => $r->active,
            ], $ctx->resources),
        ];

        return "Snapshot:\n" . json_encode($root, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
