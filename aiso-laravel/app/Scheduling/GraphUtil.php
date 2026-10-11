<?php

namespace App\Scheduling;

/**
 * Pure graph helpers over operation ids. No framework or persistence dependencies.
 */
final class GraphUtil
{
    /**
     * Finds a dependency cycle.
     *
     * @param array<string, string[]> $predecessors operation id => ids of its predecessors
     * @return string[] the cycle as a path whose first and last element are equal (A, B, C, A), or an empty array
     */
    public static function findCycle(array $predecessors): array
    {
        $state = []; // 1 = on stack, 2 = done
        $keys = array_keys($predecessors);
        sort($keys, SORT_STRING);
        foreach ($keys as $start) {
            if (($state[$start] ?? 0) === 0) {
                $path = [];
                $cycle = self::dfs((string) $start, $predecessors, $state, $path);
                if ($cycle !== []) {
                    return $cycle;
                }
            }
        }

        return [];
    }

    private static function dfs(string $node, array $preds, array &$state, array &$path): array
    {
        $state[$node] = 1;
        $path[] = $node;
        $next = array_values($preds[$node] ?? []);
        sort($next, SORT_STRING);
        foreach ($next as $p) {
            $s = $state[$p] ?? 0;
            if ($s === 1) {
                $idx = array_search($p, $path, true);
                $cycle = array_slice($path, $idx);
                $cycle[] = $p;

                return $cycle;
            }
            if ($s === 0) {
                $cycle = self::dfs($p, $preds, $state, $path);
                if ($cycle !== []) {
                    return $cycle;
                }
            }
        }
        array_pop($path);
        $state[$node] = 2;

        return [];
    }

    /**
     * Longest remaining duration from each operation to the end of the project, including the operation itself.
     *
     * @param array<string, float> $hours      operation id => duration in hours (only ids in this map count)
     * @param array<string, string[]> $successors operation id => ids of operations depending on it
     * @return array<string, float>
     */
    public static function tailHours(array $hours, array $successors): array
    {
        $memo = [];
        foreach (array_keys($hours) as $id) {
            self::tail((string) $id, $hours, $successors, $memo, []);
        }

        return $memo;
    }

    private static function tail(string $id, array $hours, array $succ, array &$memo, array $visiting): float
    {
        if (array_key_exists($id, $memo)) {
            return $memo[$id];
        }
        if (isset($visiting[$id])) {
            return 0.0; // defensive: cycles are rejected at import
        }
        $visiting[$id] = true;
        $best = 0.0;
        foreach ($succ[$id] ?? [] as $s) {
            if (array_key_exists($s, $hours)) {
                $best = max($best, self::tail($s, $hours, $succ, $memo, $visiting));
            }
        }
        $value = (float) ($hours[$id] ?? 0.0) + $best;
        $memo[$id] = $value;

        return $value;
    }
}
