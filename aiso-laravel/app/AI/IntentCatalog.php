<?php

namespace App\AI;

use App\AI\Semantic\SemanticRanker;
use App\AI\Semantic\TextSimilarity;
use App\Models\AppRole;
use App\Models\AppUser;

/**
 * What the AI may ask for, described so it can choose well, and the semantic helpers around it: hints (the intents that
 * best fit the question), and the rescue of an intent or department the AI named loosely. Nothing outside the config is
 * ever offered or resolved to.
 */
class IntentCatalog
{
    public function __construct(private readonly SemanticRanker $ranker)
    {
    }

    /** How meaning is being matched right now: with an embedding model, or by text only. For the trace. */
    public function semanticMode(): string
    {
        return $this->ranker->mode();
    }

    /** @param array<string, array{kind: string, name?: string}> $allowed @return array<string, string> "slug|intent" => text */
    private function docs(array $allowed, ?string $onlySlug = null): array
    {
        $docs = [];
        foreach ($allowed as $slug => $d) {
            if ($onlySlug !== null && $slug !== $onlySlug) {
                continue;
            }
            foreach (config("aiso.agent.kinds.{$d['kind']}.intents") as $name => $spec) {
                $docs["{$slug}|{$name}"] = str_replace('_', ' ', $name) . ' ' . ($spec['description'] ?? '') . ' ' . ($spec['keywords'] ?? '');
            }
        }

        return $docs;
    }

    /**
     * The intents that fit the question best, to help the AI choose.
     *
     * @return array<int, array{department: string, intent: string, score: float}>
     */
    public function hints(string $question, array $allowed): array
    {
        $docs = $this->docs($allowed);
        $scores = [];
        foreach ($this->ranker->rank($question, $docs, count($docs)) as [$key, $score]) {
            $scores[$key] = $score;
        }
        $hintMin = $this->ranker->threshold('hint_min');
        // a department named in the question is very likely the one meant
        $q = TextSimilarity::normalize($question);
        foreach ($allowed as $slug => $d) {
            $name = TextSimilarity::normalize($d['name'] ?? '');
            if (($name !== '' && str_contains($q, $name)) || preg_match('/\b' . preg_quote($slug, '/') . '\b/u', $q) === 1) {
                foreach ($scores as $key => $score) {
                    if (str_starts_with($key, $slug . '|')) {
                        $scores[$key] = $score + 0.15;
                    }
                }
            }
        }
        // an operation id such as OP-12 points at the operation detail
        if (preg_match('/\b[a-z]{1,6}-?\d+\b/i', $question) === 1 && isset($docs['production|operation_detail'])) {
            $scores['production|operation_detail'] = max($scores['production|operation_detail'] ?? 0, 0.5);
        }

        arsort($scores);
        $out = [];
        foreach (array_slice($scores, 0, (int) config('aiso.agent.semantic.hints'), true) as $key => $score) {
            if ($score < $hintMin) {
                continue;
            }
            [$slug, $intent] = explode('|', $key, 2);
            $out[] = ['department' => $slug, 'intent' => $intent, 'score' => round(min($score, 1.0), 2)];
        }

        return $out;
    }

    /** The real intent closest to a name the AI made up, only when it is clearly the best fit. */
    public function resolveIntent(string $slug, string $given, array $allowed): ?string
    {
        $ranked = $this->ranker->rank($given, $this->docs($allowed, $slug), 2);
        if ($ranked === []) {
            return null;
        }
        $best = $ranked[0][1];
        $second = $ranked[1][1] ?? 0.0;
        if ($best < $this->ranker->threshold('resolve_min') || $best - $second < $this->ranker->threshold('resolve_margin')) {
            return null;
        }

        return explode('|', $ranked[0][0], 2)[1];
    }

    /** The offered department closest to a name or slug the AI wrote («تولید» for production), when clearly the best. */
    public function resolveDepartment(string $given, array $allowed): ?string
    {
        $docs = [];
        foreach ($allowed as $slug => $d) {
            $docs[$slug] = $slug . ' ' . ($d['name'] ?? '');
        }
        $ranked = TextSimilarity::rank($given, $docs, 2);
        if ($ranked === [] || $ranked[0][1] < 0.6 || $ranked[0][1] - ($ranked[1][1] ?? 0.0) < 0.15) {
            return null;
        }

        return (string) $ranked[0][0];
    }

    /**
     * The catalogue the AI sees: each offered department with its intents, what each one is for and its parameters.
     *
     * @return array<int, array<string, mixed>>
     */
    public function describe(array $allowed): array
    {
        $out = [];
        foreach ($allowed as $slug => $d) {
            $intents = [];
            foreach (config("aiso.agent.kinds.{$d['kind']}.intents") as $name => $spec) {
                $intents[] = [
                    'intent' => $name, 'for' => $spec['description'] ?? '', 'returns' => $spec['returns'] ?? '', 'reads' => $spec['calls'] ?? '',
                    'params' => (object) ($spec['params'] ?? []),
                ];
            }
            $out[] = [
                'slug' => $slug, 'name' => $d['name'] ?? $slug, 'purpose' => config("aiso.agent.kinds.{$d['kind']}.purpose", ''),
                'summary' => $d['summary'] ?? '', 'intents' => $intents,
            ];
        }

        return $out;
    }

    /**
     * Who the person is and what each role and department permission means, so the AI proposes only what that person may
     * do and can say why something is not possible.
     *
     * @return array<string, mixed>
     */
    public function guide(AppUser $user): array
    {
        $g = config('aiso.agent.guide');
        // the company's own roles, with what each one may do; the built-in ones keep their hand-written meaning
        $roles = [];
        foreach (AppRole::query()->orderBy('sort_order')->orderBy('key')->get() as $r) {
            $roles[$r->key] = ($g['roles'][$r->key] ?? $r->name)
                . ($r->description ? ' — ' . $r->description : '')
                . ($r->capabilities === [] ? '' : ' (capabilities: ' . implode(', ', $r->capabilities) . ')')
                . ($r->receives_tasks ? ' (receives tasks)' : '');
        }

        return [
            'you' => ['id' => $user->id, 'role' => $user->user_role, 'meaning' => $roles[$user->user_role] ?? '',
                'capabilities' => $user->capabilities()],
            'roles' => $roles, 'department_roles' => $g['department_roles'], 'permissions' => $g['permissions'], 'approval' => $g['approval'],
        ];
    }

    /** The same, as short lines for the answer stage ("production: status_summary, list_by_status(status,limit)"). */
    public function available(array $allowed): array
    {
        $out = [];
        foreach ($allowed as $slug => $d) {
            $out[$slug] = array_map(
                fn (string $name, array $spec) => $name . ($spec['params'] === [] ? '' : '(' . implode(',', array_keys($spec['params'])) . ')'),
                array_keys(config("aiso.agent.kinds.{$d['kind']}.intents")),
                config("aiso.agent.kinds.{$d['kind']}.intents"),
            );
        }

        return $out;
    }

    /**
     * The capability an action needs: its own when it names one, otherwise the manage capability of its department. Null
     * means every person may propose it.
     */
    public static function actionCapability(string $kind, array $spec): ?string
    {
        return array_key_exists('capability', $spec) ? $spec['capability'] : config("aiso.agent.kinds.{$kind}.manage");
    }

    /** The valid intent names of one department, for an error message the AI can act on. */
    public function names(string $slug, array $allowed): string
    {
        return implode(', ', array_keys(config("aiso.agent.kinds.{$allowed[$slug]['kind']}.intents")));
    }
}
