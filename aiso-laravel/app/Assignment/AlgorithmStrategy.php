<?php

namespace App\Assignment;

/**
 * Deterministic dispatching rule (same input, same output, fully explainable).
 *  1. Rank READY operations: project priority first, then the optimised plan's order (earlier planned start), then
 *     critical-path weight (longest remaining chain first), then longer own duration, then id.
 *  2. For each operation in that order, skip it if its resource has no free slot.
 *  3. Among the users allowed by {@see AssignmentState}, pick the one with the lowest effective load, where the
 *     resource's responsible user gets a configurable bonus (a soft preference). Ties: fewer tasks, then id.
 *  4. Update loads and resource slots, and continue.
 */
final class AlgorithmStrategy implements AssignmentStrategy
{
    public function mode(): string
    {
        return 'ALGORITHM';
    }

    public function suggest(AssignmentContext $ctx): Suggestion
    {
        $state = new AssignmentState($ctx);
        $ranked = $ctx->ready;
        usort($ranked, static function (ReadyOp $a, ReadyOp $b): int {
            return ($a->projectRank <=> $b->projectRank)
                ?: (round($a->plannedStartHours * 1e6) <=> round($b->plannedStartHours * 1e6))
                ?: ($b->tailHours <=> $a->tailHours)
                ?: ($b->hours <=> $a->hours)
                ?: strcmp($a->id, $b->id);
        });

        $picks = [];
        $skips = [];
        $rank = 0;
        foreach ($ranked as $op) {
            $rank++;
            $res = $state->resources()[$op->resourceId] ?? null;
            if ($res === null || !$res->active) {
                $skips[] = new Skip($op->id, "Resource {$op->resourceName} is inactive");
                continue;
            }
            if ($state->freeSlots($op->resourceId) <= 0) {
                $skips[] = new Skip($op->id, "Resource {$res->name} has no free slot ({$res->capacity} of {$res->capacity} busy)");
                continue;
            }
            $allowed = array_values(array_filter($ctx->users, static fn (UserInfo $u) => $state->reject($op->id, $u->id) === null));
            if ($allowed === []) {
                $why = $op->fixedUserId !== null
                    ? ($op->forcedUser ? 'Forced executor ' : 'Fixed executor ') . $op->fixedUserId
                        . ' is unavailable: ' . ($state->reject($op->id, $op->fixedUserId) ?? 'not allowed')
                    : "No executive user is below the active-task limit ({$ctx->maxActiveTasksPerUser})";
                $skips[] = new Skip($op->id, $why);
                continue;
            }
            $chosen = null;
            $chosenScore = 0.0;
            foreach ($allowed as $u) {
                $score = self::score($u, $res, $state, $ctx);
                if ($chosen === null
                    || $score < $chosenScore
                    || ($score == $chosenScore && $state->tasksOf($u->id) < $state->tasksOf($chosen->id))
                    || ($score == $chosenScore && $state->tasksOf($u->id) == $state->tasksOf($chosen->id) && strcmp($u->id, $chosen->id) < 0)) {
                    $chosen = $u;
                    $chosenScore = $score;
                }
            }

            if ($op->forcedUser) {
                $who = 'forced by the manager';
            } elseif ($op->fixedUserId !== null) {
                $who = 'fixed executor in OPC';
            } elseif ($chosen->id === $res->responsibleUserId) {
                $who = sprintf('responsible user of %s, load %.1f h', $res->name, $state->loadOf($chosen->id));
            } else {
                $who = sprintf('lowest load (%.1f h, %d active)', $state->loadOf($chosen->id), $state->tasksOf($chosen->id));
            }
            $reason = sprintf('Rank %d (chain %.1f h); %s slot %d of %d; %s: %s',
                    $rank, $op->tailHours, $res->name, $state->usedSlots($op->resourceId) + 1, $res->capacity,
                    $chosen->fullName, $who)
                . ($op->projectRank > 0 ? sprintf('; project %s (priority %d)', $op->projectName, $op->projectRank) : '');
            $picks[] = new Pick($op->id, $chosen->id, $reason);
            $state->apply($op->id, $chosen->id);
        }

        $summary = count($picks) . ' of ' . count($ranked) . ' READY operations proposed by the dispatching rule'
            . ($skips === [] ? '.' : '; ' . count($skips) . ' left unassigned.');

        return Suggestion::of($picks, $skips, $summary);
    }

    private static function score(UserInfo $user, ResourceInfo $res, AssignmentState $state, AssignmentContext $ctx): float
    {
        $load = $state->loadOf($user->id);

        return $user->id === $res->responsibleUserId ? $load - $ctx->responsibleBonusHours : $load;
    }
}
