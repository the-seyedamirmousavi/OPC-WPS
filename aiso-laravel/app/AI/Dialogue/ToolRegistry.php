<?php

namespace App\AI\Dialogue;

use App\AI\IntentCatalog;
use App\Models\AppUser;

/**
 * The read tools the assistant may call, derived from the intent config (config aiso.agent.kinds.*.intents), which stays
 * the only definition. A tool is "{department slug}.{intent}": production.status_summary, hr.search_users,
 * dept-4.overview. Only the departments the person may open and the intents their capabilities allow are listed. Every
 * tool here only reads; changes are actions, which go through ActionExecutor and its approval policy.
 */
class ToolRegistry
{
    /** Parameters that name a record; a name the user writes for one of them is resolved by EntityResolver. */
    public const ENTITY_PARAMS = ['user_id', 'project_id', 'operation_id', 'resource_id', 'department_id'];

    public function __construct(private readonly IntentCatalog $catalog)
    {
    }

    /**
     * Every tool this person may call, by name.
     *
     * @param array<string, array{kind: string, name: string}> $allowed the departments from DepartmentRegistry::forUser
     * @return array<string, array{name: string, slug: string, kind: string, intent: string, department: string, description: string,
     *         returns: string, source: string, capability: ?string, params: array<string, string>, required: string[], read_only: true}>
     */
    public function forUser(AppUser $user, array $allowed): array
    {
        $out = [];
        foreach ($allowed as $slug => $d) {
            foreach (config("aiso.agent.kinds.{$d['kind']}.intents") as $intent => $spec) {
                $capability = $spec['capability'] ?? null;
                if ($capability !== null && !$user->allows($capability)) {
                    continue;
                }
                $name = "{$slug}.{$intent}";
                $out[$name] = [
                    'name' => $name, 'slug' => $slug, 'kind' => $d['kind'], 'intent' => $intent, 'department' => $d['name'] ?? $slug,
                    'description' => $spec['description'] ?? '', 'returns' => $spec['returns'] ?? '', 'source' => $spec['calls'] ?? '',
                    'capability' => $capability, 'params' => $spec['params'] ?? [], 'required' => self::required($spec['params'] ?? []),
                    'read_only' => true,
                ];
            }
        }

        return $out;
    }

    /** @param array<string, string> $rules @return string[] the parameters a tool cannot run without */
    public static function required(array $rules): array
    {
        return array_keys(array_filter($rules, static fn (string $rule) => in_array('required', explode('|', $rule), true)));
    }

    /**
     * One line per tool, the list the model always sees: "production.project_detail(project_id*): جزئیات یک پروژه".
     *
     * @param array<string, array> $tools
     * @return string[]
     */
    public static function index(array $tools): array
    {
        $lines = [];
        foreach ($tools as $t) {
            $keys = array_values(array_filter(array_keys($t['params']), static fn (string $p) => !str_contains($p, '.*')));
            $params = array_map(static fn (string $p) => in_array($p, $t['required'], true) ? "{$p}*" : $p, $keys);
            $lines[] = $t['name'] . '(' . implode(',', $params) . '): ' . self::shortText($t['description']);
        }

        return $lines;
    }

    /**
     * The full schema of a few tools: what each returns and each parameter's type and rule.
     *
     * @param array<string, array> $tools
     * @param string[] $names
     * @return array<string, array{returns: string, params: array<string, string>}>
     */
    public static function details(array $tools, array $names): array
    {
        $out = [];
        foreach ($names as $n) {
            if (isset($tools[$n])) {
                $out[$n] = ['returns' => $tools[$n]['returns'], 'params' => (object) array_map([self::class, 'paramText'], $tools[$n]['params'])];
            }
        }

        return $out;
    }

    /**
     * The tools most likely meant, so only their schemas are shown: the pending tool first, then the semantic hints, then
     * the summary tool of a department the message names. This only chooses what to describe; it never authorizes.
     *
     * @param array<string, array> $tools
     * @return string[]
     */
    public function relevant(string $message, array $tools, array $allowed, ?string $pending, int $max): array
    {
        $names = $pending !== null && isset($tools[$pending]) ? [$pending] : [];
        foreach ($this->catalog->hints($message, $allowed) as $h) {
            $names[] = "{$h['department']}.{$h['intent']}";
        }

        return array_values(array_slice(array_unique(array_filter($names, static fn (string $n) => isset($tools[$n]))), 0, $max));
    }

    /** "user_id" => "string, required, max 64"; enough for the model to fill it in. */
    private static function paramText(string $rule): string
    {
        $parts = explode('|', $rule);
        $type = in_array('integer', $parts, true) ? 'integer' : (in_array('boolean', $parts, true) ? 'boolean' : (in_array('date', $parts, true) ? 'date YYYY-MM-DD' : 'string'));
        $notes = [$type, in_array('required', $parts, true) ? 'required' : 'optional'];
        foreach ($parts as $p) {
            if (str_starts_with($p, 'in:')) {
                $notes[] = 'one of ' . substr($p, 3);
            } elseif (str_starts_with($p, 'max:') && $type === 'integer') {
                $notes[] = 'max ' . substr($p, 4);
            }
        }

        return implode(', ', $notes);
    }

    private static function shortText(string $s): string
    {
        $s = trim(preg_replace('/\s+/u', ' ', $s) ?? $s);

        return mb_strlen($s) > 90 ? mb_substr($s, 0, 88) . '…' : $s;
    }
}
