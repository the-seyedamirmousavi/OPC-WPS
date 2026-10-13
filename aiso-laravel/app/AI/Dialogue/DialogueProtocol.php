<?php

namespace App\AI\Dialogue;

use App\AI\ProtocolException;
use App\AI\ProtocolValidator;

/**
 * The messages the model writes in a dialogue turn (protocol aiso.v2, internal to the server). One of:
 *   {"protocol":"aiso.v2","type":"reply","text":"…","choices":[],"actions":[{"action":"production.cancel_operation","args":{},"reason":"…"}]}
 *   {"protocol":"aiso.v2","type":"call","calls":[{"tool":"production.status_summary","args":{}}]}
 *   {"protocol":"aiso.v2","type":"ask","question":"…","pending":{"tool":"production.list_by_user","args":{},"missing":["user_id"]},"choices":[]}
 * Shapes are checked here; tools, parameters, rights and approvals are checked by the engine, never trusted from here.
 */
class DialogueProtocol
{
    public const PROTOCOL = 'aiso.v2';

    public function __construct(private readonly ProtocolValidator $v1)
    {
    }

    /**
     * @param bool $lossy also allow the last-resort repair that cuts a broken tail (used only after a correction failed)
     * @return array{type: string, text?: string, choices?: string[], actions?: array, calls?: array, question?: string, pending?: ?array}
     */
    public function parse(string $content, bool $lossy = false): array
    {
        $m = $this->v1->decode($lossy ? self::repair($content) : self::mend($content));
        if (($m['protocol'] ?? null) !== self::PROTOCOL) {
            throw new ProtocolException('protocol must be ' . self::PROTOCOL);
        }

        return match ($m['type'] ?? null) {
            'reply' => $this->reply($m),
            'call' => $this->call($m),
            'ask' => $this->ask($m),
            default => throw new ProtocolException('type must be reply, call or ask'),
        };
    }

    private function reply(array $m): array
    {
        $text = $m['text'] ?? null;
        if (!is_string($text) || trim($text) === '') {
            throw new ProtocolException('reply needs text');
        }

        return ['type' => 'reply', 'text' => trim($text), 'choices' => self::choices($m['choices'] ?? null), 'actions' => self::actions($m)];
    }

    /** The proposed changes of a message, in any message type (models sometimes put them next to tool calls). */
    private static function actions(array $m): array
    {
        $actions = [];
        foreach (is_array($m['actions'] ?? null) ? $m['actions'] : [] as $a) {
            if (is_array($a) && is_string($a['action'] ?? null)) {
                $actions[] = ['action' => trim($a['action']), 'args' => is_array($a['args'] ?? null) ? $a['args'] : [],
                    'reason' => is_string($a['reason'] ?? null) ? mb_substr(trim($a['reason']), 0, 300)
                        : (is_string($m['reason'] ?? null) ? mb_substr(trim($m['reason']), 0, 300) : '')];
            }
        }

        return $actions;
    }

    private function call(array $m): array
    {
        $calls = [];
        foreach (is_array($m['calls'] ?? null) ? $m['calls'] : [] as $c) {
            if (is_array($c) && is_string($c['tool'] ?? null)) {
                $calls[] = ['tool' => trim($c['tool']), 'args' => $c['args'] ?? []];
            }
        }
        $actions = self::actions($m);
        if ($calls === [] && $actions === []) {
            throw new ProtocolException('call needs at least one {"tool": "...", "args": {}}');
        }

        return ['type' => 'call', 'calls' => $calls, 'actions' => $actions];
    }

    private function ask(array $m): array
    {
        $q = $m['question'] ?? null;
        if (!is_string($q) || trim($q) === '') {
            throw new ProtocolException('ask needs question');
        }
        $p = $m['pending'] ?? null;
        $pending = is_array($p) && is_string($p['tool'] ?? null)
            ? ['tool' => trim($p['tool']), 'args' => is_array($p['args'] ?? null) ? $p['args'] : [],
                'missing' => array_values(array_filter(is_array($p['missing'] ?? null) ? $p['missing'] : [], 'is_string'))]
            : null;

        return ['type' => 'ask', 'question' => trim($q), 'pending' => $pending, 'choices' => self::choices($m['choices'] ?? null)];
    }

    /**
     * Two slips small models make often, both mechanical, mended only when the answer is not valid JSON as written:
     *  - the arguments straight after the tool name, {"tool":"x":{…}}, become {"tool":"x","args":{…}};
     *  - a backslash before a character that cannot be escaped ("\ه") is dropped.
     * Nothing else is changed, and the result is still fully checked.
     */
    public static function repair(string $content): string
    {
        $t = self::mend($content);
        if (json_decode($t) !== null) {
            return $t;
        }
        // last resort, seen live: a broken tail (quick replies like {"text":"…","}, or actions with a misplaced bracket). The
        // message is cut before "choices", then before "actions", and kept only if what remains is a valid message. Cutting
        // drops quick replies or proposals: fewer, never more.
        foreach ([',"choices"', ',"actions"'] as $tail) {
            $at = strpos($t, $tail);
            if ($at === false) {
                continue;
            }
            $rest = substr($t, 0, $at) . '}';
            $decoded = json_decode($rest, true);
            if (is_array($decoded) && in_array($decoded['type'] ?? null, ['reply', 'call', 'ask'], true)) {
                return $rest;
            }
        }

        return $t;
    }

    /** The repairs that lose nothing: the misplaced arguments and the stray backslash. Valid JSON is returned unchanged. */
    public static function mend(string $content): string
    {
        $t = trim($content);
        if (preg_match('/\A```(?:json)?\s*(.*?)\s*```\z/s', $t, $m) === 1) {
            $t = $m[1];
        }
        if (json_decode($t) !== null) {
            return $content;
        }
        $t = preg_replace('/"tool"\s*:\s*"([^"\\\\]+)"\s*:\s*\{/u', '"tool":"$1","args":{', $t) ?? $t;

        return preg_replace_callback('/\\\\(.?)/su', static fn (array $m) => in_array($m[1], ['"', '\\', '/', 'b', 'f', 'n', 'r', 't', 'u'], true) ? $m[0] : $m[1], $t) ?? $t;
    }

    /** At most four short quick replies. @return string[] */
    public static function choices(mixed $raw): array
    {
        $out = [];
        foreach (is_array($raw) ? $raw : [] as $c) {
            // {"text": "…"} is read as its text
            if (is_array($c) && is_string($c['text'] ?? null)) {
                $c = $c['text'];
            }
            if (is_string($c) && ($c = trim($c)) !== '' && mb_strlen($c) <= 60 && !in_array($c, $out, true)) {
                $out[] = $c;
            }
        }

        return array_slice($out, 0, 4);
    }
}
