<?php

namespace App\AI\Dialogue;

use App\AI\SensitiveDataFilter;
use Carbon\CarbonImmutable;

/**
 * What the assistant remembers between turns of one conversation. Small and structured, never the transcript:
 *  - turns: the last few exchanges, each cut short (the rolling summary)
 *  - pending: a tool waiting for parameters the user has not given yet, with the question asked and, when a name matched
 *    several records, the candidates offered
 *  - focus: the records the conversation is about (the project, the person, the operation), for "چرا؟" and "اون یکی"
 *  - recent: a short note of the last tool results
 *  - last_question: the question the assistant ended its last reply with, so "آره" can be understood
 * The state holds no secrets: everything put in it is scrubbed, and tool results are kept only as short notes.
 */
final class DialogueState
{
    /** @param array<string, mixed> $data */
    private function __construct(public array $data)
    {
    }

    public static function fresh(): self
    {
        return new self(['turns' => [], 'pending' => null, 'focus' => [], 'recent' => [], 'last_question' => null, 'goal' => null, 'updated_at' => null]);
    }

    /**
     * Reads a stored state. A state older than state_ttl_hours starts over; a pending question older than
     * pending_ttl_minutes is dropped and reported (so it can never run late).
     */
    public static function load(?string $json, ?CarbonImmutable $now = null): self
    {
        $now ??= CarbonImmutable::now();
        $data = $json === null ? null : json_decode($json, true);
        if (!is_array($data)) {
            return self::fresh();
        }
        $state = new self(array_merge(self::fresh()->data, $data));
        $updated = isset($data['updated_at']) ? CarbonImmutable::parse($data['updated_at']) : null;
        if ($updated === null || $updated->lt($now->subHours((int) config('aiso.agent.dialogue.state_ttl_hours')))) {
            return self::fresh();
        }
        $pending = $state->data['pending'];
        if (is_array($pending) && CarbonImmutable::parse($pending['created_at'])->lt($now->subMinutes((int) config('aiso.agent.dialogue.pending_ttl_minutes')))) {
            $state->data['pending'] = null;
            $state->data['expired'] = $pending['tool'];
        }

        return $state;
    }

    public function toJson(): string
    {
        $data = $this->data;
        unset($data['expired']);
        $data['updated_at'] = CarbonImmutable::now()->toIso8601String();

        return (string) json_encode($data, JSON_UNESCAPED_UNICODE);
    }

    public function pending(): ?array
    {
        return is_array($this->data['pending']) ? $this->data['pending'] : null;
    }

    /** The tool of a pending question that expired while loading, if any. */
    public function expired(): ?string
    {
        return $this->data['expired'] ?? null;
    }

    /** @param array<string, mixed> $args @param string[] $missing */
    public function setPending(string $tool, array $args, array $missing, string $question, string $origin, ?string $param = null, ?array $candidates = null): void
    {
        $this->data['pending'] = [
            'tool' => $tool, 'args' => $args, 'missing' => array_values($missing), 'question' => $question,
            'origin' => mb_substr(SensitiveDataFilter::scrub($origin), 0, 300), 'param' => $param, 'candidates' => $candidates,
            'created_at' => CarbonImmutable::now()->toIso8601String(),
        ];
        $this->data['last_question'] = $question;
    }

    public function clearPending(): void
    {
        $this->data['pending'] = null;
    }

    /** Remembers the records a successful tool was about, by parameter (project_id, user_id, ...). */
    public function focus(string $param, string|int $id, ?string $label = null): void
    {
        $this->data['focus'][$param] = ['id' => $id, 'label' => $label === null ? null : mb_substr($label, 0, 80)];
    }

    /** A short note of a tool result, for follow-up questions. Only the last few are kept. */
    public function remember(array $result): void
    {
        $note = ['tool' => $result['tool'], 'args' => $result['args'], 'ok' => $result['ok'], 'brief' => self::brief($result)];
        $recent = array_values(array_filter($this->data['recent'], static fn (array $r) => $r['tool'] !== $result['tool'] || $r['args'] != $result['args']));
        $recent[] = $note;
        $this->data['recent'] = array_slice($recent, -(int) config('aiso.agent.dialogue.recent_results'));
    }

    /**
     * The task the user is waiting for. It starts with the message the assistant answered with a question, and stays
     * through the short replies that follow ("بله", "عمومی"); a turn that ends with a plain answer completes it.
     */
    public function trackGoal(string $message, bool $stillOpen): void
    {
        if ($stillOpen) {
            $this->data['goal'] ??= mb_substr(SensitiveDataFilter::scrub($message), 0, 500);
        } else {
            $this->data['goal'] = null;
        }
    }

    /** Adds one exchange to the rolling summary, both sides cut short. */
    public function addTurn(string $user, string $assistant, bool $endsWithQuestion): void
    {
        $this->data['turns'][] = [
            'u' => mb_substr(SensitiveDataFilter::scrub($user), 0, 400),
            'a' => mb_substr(SensitiveDataFilter::scrub($assistant), 0, 300),
        ];
        $this->data['turns'] = array_slice($this->data['turns'], -(int) config('aiso.agent.dialogue.summary_turns'));
        if ($this->pending() === null) {
            $this->data['last_question'] = $endsWithQuestion ? mb_substr(SensitiveDataFilter::scrub($assistant), -200) : null;
        }
    }

    /** What the model sees of the state: only the parts that are set. */
    public function forModel(): array
    {
        $out = [];
        if (($this->data['goal'] ?? null) !== null) {
            $out['goal'] = $this->data['goal'];
        }
        if ($this->data['turns'] !== []) {
            $out['summary'] = $this->data['turns'];
        }
        if (($p = $this->pending()) !== null) {
            $out['pending'] = ['tool' => $p['tool'], 'have' => (object) $p['args'], 'missing' => $p['missing'], 'asked' => $p['question'],
                'original_request' => $p['origin']];
        }
        if ($this->data['focus'] !== []) {
            $out['focus'] = $this->data['focus'];
        }
        if ($this->data['recent'] !== []) {
            $out['recent'] = $this->data['recent'];
        }
        if ($this->data['last_question'] !== null && $this->pending() === null) {
            $out['you_asked'] = $this->data['last_question'];
        }
        if ($this->expired() !== null) {
            $out['note'] = 'an earlier unanswered question expired; do not continue it unless the user asks again';
        }

        return $out;
    }

    /** One line about a result: its size and the first few ids or names, never the rows. */
    private static function brief(array $result): string
    {
        if (!$result['ok']) {
            return 'failed: ' . ($result['status'] ?? '');
        }
        $data = $result['data'];
        if (is_array($data) && array_is_list($data)) {
            $ids = array_slice(array_filter(array_map(static fn ($r) => is_array($r) ? ($r['id'] ?? $r['name'] ?? null) : null, $data)), 0, 8);

            return count($data) . ' rows' . (($result['truncated'] ?? false) ? ' (more exist)' : '') . ($ids === [] ? '' : ': ' . implode(', ', $ids));
        }
        if (is_array($data)) {
            $scalars = array_filter($data, static fn ($v) => is_scalar($v) || $v === null);
            $text = json_encode(array_slice($scalars, 0, 10, true), JSON_UNESCAPED_UNICODE) ?: '';

            return mb_substr(SensitiveDataFilter::scrub($text), 0, 400);
        }

        return mb_substr(SensitiveDataFilter::scrub((string) json_encode($data, JSON_UNESCAPED_UNICODE)), 0, 200);
    }
}
