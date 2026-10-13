<?php

namespace App\AI;

use Illuminate\Support\Facades\Validator;

/**
 * Checks every message from the AI against protocol aiso.v1 and against the registry. An invalid request or action is
 * dropped (and reported in `dropped`); it is never executed. A message that cannot be used at all throws
 * ProtocolException.
 */
class ProtocolValidator
{
    public const PROTOCOL = 'aiso.v1';

    /** Reads the JSON the AI wrote. A fenced block is tolerated; any other text around the JSON is not. */
    public function decode(string $text): array
    {
        $t = trim($text);
        if (preg_match('/\A```(?:json)?\s*(.*?)\s*```\z/s', $t, $m) === 1) {
            $t = $m[1];
        }
        $data = json_decode($t, true);
        if (!is_array($data) || array_is_list($data)) {
            throw new ProtocolException('The message is not a JSON object');
        }

        return $data;
    }

    /**
     * @param array<string, array{kind: string}> $allowed the departments offered to the user, by slug
     * @return array{decision: string, requests: array<int, array>, clarify_question: ?string, choices: string[], dropped: string[]}
     */
    public function route(array $m, array $allowed): array
    {
        $this->header($m, 'route_result');
        $decision = $m['decision'] ?? null;
        if (!in_array($decision, ['fetch', 'answer', 'clarify'], true)) {
            throw new ProtocolException('decision must be fetch, answer or clarify');
        }
        if ($decision === 'clarify') {
            $q = $m['clarify_question'] ?? null;
            if (!is_string($q) || trim($q) === '') {
                throw new ProtocolException('clarify needs clarify_question');
            }

            return ['decision' => 'clarify', 'requests' => [], 'clarify_question' => trim($q), 'choices' => $this->choices($m['choices'] ?? null), 'dropped' => []];
        }
        if ($decision === 'answer') {
            return ['decision' => 'answer', 'requests' => [], 'clarify_question' => null, 'choices' => [], 'dropped' => []];
        }

        [$requests, $dropped] = $this->requests($m['requests'] ?? null, $allowed);
        if ($requests === []) {
            throw new ProtocolException('fetch needs at least one valid request. ' . implode('. ', $dropped));
        }

        return ['decision' => 'fetch', 'requests' => $requests, 'clarify_question' => null, 'choices' => [], 'dropped' => $dropped];
    }

    /**
     * @param array<string, array{kind: string}> $allowed
     * @return array{decision: string, text: ?string, fetch_more: array<int, array>, actions: array<int, array>, choices: string[], dropped: string[]}
     */
    public function answer(array $m, array $allowed): array
    {
        $this->header($m, 'answer_result');
        $decision = $m['decision'] ?? null;
        if (!in_array($decision, ['answer', 'fetch_more'], true)) {
            throw new ProtocolException('decision must be answer or fetch_more');
        }

        $dropped = [];
        $fetchMore = [];
        $text = null;
        if ($decision === 'answer') {
            $text = $m['text'] ?? null;
            if (!is_string($text) || trim($text) === '') {
                throw new ProtocolException('answer needs text');
            }
            $text = trim($text);
        } else {
            [$fetchMore, $dropped] = $this->requests($m['fetch_more'] ?? null, $allowed);
            if ($fetchMore === []) {
                throw new ProtocolException('fetch_more needs at least one valid request. ' . implode('. ', $dropped));
            }
        }

        $actions = [];
        $rawActions = $m['actions'] ?? [];
        foreach (is_array($rawActions) ? $rawActions : [] as $a) {
            $valid = is_array($a) ? $this->action($a, $allowed, $why) : null;
            if ($valid === null) {
                $dropped[] = 'action: ' . ($why ?? 'invalid');
            } else {
                $actions[] = $valid;
            }
        }

        // quick replies belong to an answer that asks something; a fetch-more round has none
        $choices = $decision === 'answer' ? $this->choices($m['choices'] ?? null) : [];

        return ['decision' => $decision, 'text' => $text, 'fetch_more' => $fetchMore, 'actions' => $actions, 'choices' => $choices, 'dropped' => $dropped];
    }

    public function __construct(private readonly IntentCatalog $catalog)
    {
    }

    /**
     * One data request. A department or intent the AI named loosely is mapped to the closest real one when that is clear
     * (the original wording is kept in `resolved`); otherwise the request is dropped with a reason the AI can act on.
     *
     * @return array{department: string, intent: string, params: array, resolved?: string}|null
     */
    public function request(array $r, array $allowed, ?string &$why = null): ?array
    {
        $slug = $r['department'] ?? null;
        $intent = $r['intent'] ?? null;
        $resolved = null;

        if (is_string($slug) && !isset($allowed[$slug])) {
            $alt = $this->catalog->resolveDepartment($slug, $allowed);
            if ($alt !== null) {
                $resolved = $slug;
                $slug = $alt;
            }
        }
        if (!is_string($slug) || !isset($allowed[$slug])) {
            $why = "unknown department '" . (is_string($slug) ? $slug : '?') . "'; valid departments: " . implode(', ', array_keys($allowed));

            return null;
        }

        $spec = is_string($intent) ? config("aiso.agent.kinds.{$allowed[$slug]['kind']}.intents.{$intent}") : null;
        $given = $r['params'] ?? [];
        if ($spec === null && is_string($intent)) {
            $alt = $this->catalog->resolveIntent($slug, $intent, $allowed);
            if ($alt !== null) {
                $resolved = $intent;
                $intent = $alt;
                $spec = config("aiso.agent.kinds.{$allowed[$slug]['kind']}.intents.{$intent}");
                // the parameters were written for another name; keep only what the real intent accepts
                $given = is_array($given) ? array_intersect_key($given, $spec['params']) : [];
            }
        }
        if (!is_string($intent) || $spec === null) {
            $why = "unknown intent '" . (is_string($intent) ? $intent : '?') . "' for {$slug}; valid intents: "
                . $this->catalog->names($slug, $allowed);

            return null;
        }

        $params = $this->params($given, $spec['params'], $why);
        if ($params === null) {
            return null;
        }

        return ['department' => $slug, 'intent' => $intent, 'params' => $params] + ($resolved === null ? [] : ['resolved' => $resolved]);
    }

    /** @return array{department: string, operation: string, params: array, reason: string, risk: string}|null */
    public function action(array $a, array $allowed, ?string &$why = null): ?array
    {
        $slug = $a['department'] ?? null;
        $operation = $a['operation'] ?? null;
        if (!is_string($slug) || !isset($allowed[$slug])) {
            $why = 'unknown department';

            return null;
        }
        $spec = is_string($operation) ? config("aiso.agent.kinds.{$allowed[$slug]['kind']}.actions.{$operation}") : null;
        if ($spec === null) {
            $why = 'unknown operation';

            return null;
        }
        $params = $this->params($a['params'] ?? [], $spec['params'], $why);
        if ($params === null) {
            return null;
        }

        return [
            'department' => $slug, 'operation' => $operation, 'params' => $params,
            'reason' => mb_substr(is_string($a['reason'] ?? null) ? trim($a['reason']) : '', 0, 300), 'risk' => $spec['risk'],
        ];
    }

    /** Short replies the user can click: text, at most four, each up to 40 characters. Anything else is dropped quietly. @return string[] */
    private function choices(mixed $raw): array
    {
        $out = [];
        foreach (is_array($raw) ? $raw : [] as $c) {
            if (!is_string($c)) {
                continue;
            }
            $c = trim($c);
            if ($c === '' || mb_strlen($c) > 40 || in_array($c, $out, true)) {
                continue;
            }
            $out[] = $c;
            if (count($out) === 4) {
                break;
            }
        }

        return $out;
    }

    private function header(array $m, string $stage): void
    {
        if (($m['protocol'] ?? null) !== self::PROTOCOL) {
            throw new ProtocolException('protocol must be ' . self::PROTOCOL);
        }
        if (($m['stage'] ?? null) !== $stage) {
            throw new ProtocolException("stage must be {$stage}");
        }
    }

    /** @return array{0: array<int, array>, 1: string[]} */
    private function requests(mixed $raw, array $allowed): array
    {
        if (!is_array($raw)) {
            return [[], []];
        }
        $max = (int) config('aiso.agent.max_requests_per_round');
        $valid = [];
        $dropped = [];
        foreach (array_slice($raw, 0, $max) as $r) {
            $item = is_array($r) ? $this->request($r, $allowed, $why) : null;
            if ($item === null) {
                $dropped[] = 'request: ' . ($why ?? 'invalid');
            } else {
                $valid[] = $item;
            }
        }
        if (count($raw) > $max) {
            $dropped[] = 'request: more than ' . $max . ' requests in one round';
        }

        return [$valid, $dropped];
    }

    /** Only the parameters the config lists, each checked by its rule. @return array<string, mixed>|null */
    private function params(mixed $given, array $rules, ?string &$why): ?array
    {
        if (!is_array($given) || ($given !== [] && array_is_list($given))) {
            $why = 'params must be an object';

            return null;
        }
        $unknown = array_diff(array_keys($given), array_keys($rules));
        if ($unknown !== []) {
            $why = 'unknown parameter ' . implode(',', $unknown);

            return null;
        }
        $v = Validator::make($given, $rules);
        if ($v->fails()) {
            $why = 'invalid parameters: ' . implode('; ', $v->errors()->all());

            return null;
        }

        return $v->validated();
    }
}
