<?php

namespace App\AI\Dialogue;

use App\AI\Semantic\TextSimilarity;
use App\Models\AppUser;
use App\Models\Operation;
use App\Models\Project;
use App\Models\WorkResource;
use App\Services\DepartmentService;

/**
 * Turns what the user wrote ("نگار صادقی", "خط تولید شمال", "OP-12") into the id a tool needs. Matching is strict, not
 * semantic: the id itself, the exact name, or a name that contains or is contained in the reply. Two or more equally good
 * matches are returned as candidates for the user to choose from; nothing is ever guessed.
 *
 * Only records the person may see are candidates: production records need production.view, departments are the ones
 * the person may open, and people are offered only for a tool the person was already allowed to call (id and name only).
 */
class EntityResolver
{
    public const RESOLVED = 'resolved';
    public const AMBIGUOUS = 'ambiguous';
    public const NONE = 'none';

    private const ORDINALS = [
        'اولی' => 0, 'اول' => 0, 'یکمی' => 0, 'first' => 0, '1' => 0,
        'دومی' => 1, 'دوم' => 1, 'second' => 1, '2' => 1,
        'سومی' => 2, 'سوم' => 2, 'third' => 2, '3' => 2,
        'چهارمی' => 3, 'چهارم' => 3, 'fourth' => 3, '4' => 3,
        'پنجمی' => 4, 'پنجم' => 4, 'fifth' => 4, '5' => 4,
    ];

    public function __construct(private readonly DepartmentService $departments)
    {
    }

    /**
     * @return array{status: string, id?: string|int, label?: string, candidates?: array<int, array{id: string|int, label: string}>}
     */
    public function resolve(string $param, string $text, AppUser $user): array
    {
        $candidates = $this->candidates($param, $user);
        if ($candidates === null) {
            return ['status' => self::NONE];
        }
        $t = TextSimilarity::normalize($text);
        if ($t === '') {
            return ['status' => self::NONE];
        }
        $scores = [];
        foreach ($candidates as $i => [$id, $name]) {
            $nid = TextSimilarity::normalize((string) $id);
            $n = TextSimilarity::normalize($name);
            $score = match (true) {
                $nid !== '' && ($t === $nid || preg_match('/(^| )' . preg_quote($nid, '/') . '( |$)/u', $t) === 1) => 4,
                $n !== '' && $t === $n => 3,
                $n !== '' && mb_strlen($n) >= 3 && str_contains($t, $n) => 2,
                mb_strlen($t) >= 2 && $n !== '' && preg_match('/(^| )' . preg_quote($t, '/') . '/u', $n) === 1 => 1,
                default => 0,
            };
            if ($score > 0) {
                $scores[$i] = $score;
            }
        }
        if ($scores === []) {
            return ['status' => self::NONE];
        }
        $best = max($scores);
        $top = array_keys(array_filter($scores, static fn (int $s) => $s === $best));
        $rows = array_map(static fn (int $i) => ['id' => $candidates[$i][0], 'label' => self::label($candidates[$i])], $top);
        if (count($rows) === 1) {
            return ['status' => self::RESOLVED] + $rows[0];
        }

        return ['status' => self::AMBIGUOUS, 'candidates' => array_slice($rows, 0, 5)];
    }

    /**
     * A choice among the candidates offered last time: the label itself, an ordinal ("همون اولی", "دومی", "2") or the id.
     *
     * @param array<int, array{id: string|int, label: string}> $candidates
     * @return array{id: string|int, label: string}|null
     */
    public static function pick(string $text, array $candidates): ?array
    {
        $t = TextSimilarity::normalize($text);
        foreach ($candidates as $c) {
            if ($t === TextSimilarity::normalize($c['label']) || $t === TextSimilarity::normalize((string) $c['id'])) {
                return $c;
            }
        }
        foreach (explode(' ', $t) as $word) {
            if (isset(self::ORDINALS[$word]) && isset($candidates[self::ORDINALS[$word]])) {
                return $candidates[self::ORDINALS[$word]];
            }
        }

        return null;
    }

    /** @return array<int, array{0: string|int, 1: string}>|null id and name of what the person may see; null when nothing */
    private function candidates(string $param, AppUser $user): ?array
    {
        $pairs = static fn ($rows) => $rows->map(static fn ($r) => [$r->id, (string) ($r->name ?? $r->full_name ?? '')])->all();

        return match ($param) {
            'user_id' => $pairs(AppUser::query()->where('user_role', '!=', 'AGENT')->orderBy('id')->limit(2000)->get(['id', 'full_name'])),
            'project_id' => $user->allows('production.view') ? $pairs(Project::query()->orderBy('id')->limit(2000)->get(['id', 'name'])) : null,
            'operation_id' => $user->allows('production.view') ? $pairs(Operation::query()->orderBy('id')->limit(5000)->get(['id', 'name'])) : null,
            'resource_id' => $user->allows('production.view') ? $pairs(WorkResource::query()->orderBy('id')->limit(2000)->get(['id', 'name'])) : null,
            'department_id' => array_map(static fn (array $d) => [$d['id'], (string) $d['name']], $this->departments->menu($user)),
            default => null,
        };
    }

    /** @param array{0: string|int, 1: string} $c */
    private static function label(array $c): string
    {
        return $c[1] === '' ? (string) $c[0] : "{$c[1]} ({$c[0]})";
    }
}
