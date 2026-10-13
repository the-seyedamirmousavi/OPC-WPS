<?php

namespace Tests\Support;

use App\AI\LlmClient;
use App\Exceptions\ApiException;

/** A fake AI service: returns prepared messages in order and remembers what it was sent. */
class AgentFake extends LlmClient
{
    /** @var array<int, array> every call: the messages sent */
    public array $calls = [];

    /** @param array<int, string|\Throwable> $queue messages (JSON text or anything else) the "AI" answers with */
    public function __construct(private array $queue)
    {
    }

    public function complete(array $messages): array
    {
        $this->calls[] = $messages;
        $next = array_shift($this->queue);
        if ($next === null) {
            throw new ApiException(503, 'The fake AI has nothing left to say');
        }
        if ($next instanceof \Throwable) {
            throw $next;
        }

        return ['content' => $next, 'tokens_in' => 10, 'tokens_out' => 5, 'model' => 'fake-model'];
    }

    /** Adds more messages to say, so one fake can serve several requests of a test. */
    public function push(string|\Throwable ...$more): void
    {
        array_push($this->queue, ...$more);
    }

    /** The JSON the backend sent in call number $i (0-based): the user message. */
    public function input(int $i): array
    {
        return json_decode($this->calls[$i][1]['content'], true);
    }

    // ---- messages as the AI would write them (protocol aiso.v1) ----

    public static function route(string $decision, array $requests = [], ?string $clarify = null): string
    {
        return json_encode(['protocol' => 'aiso.v1', 'stage' => 'route_result', 'decision' => $decision,
            'requests' => $requests, 'clarify_question' => $clarify], JSON_UNESCAPED_UNICODE);
    }

    public static function answer(string $text, array $actions = []): string
    {
        return json_encode(['protocol' => 'aiso.v1', 'stage' => 'answer_result', 'decision' => 'answer', 'text' => $text,
            'fetch_more' => [], 'actions' => $actions], JSON_UNESCAPED_UNICODE);
    }

    public static function fetchMore(array $requests): string
    {
        return json_encode(['protocol' => 'aiso.v1', 'stage' => 'answer_result', 'decision' => 'fetch_more', 'text' => null,
            'fetch_more' => $requests, 'actions' => []], JSON_UNESCAPED_UNICODE);
    }

    public static function req(string $department, string $intent, array $params = []): array
    {
        return ['department' => $department, 'intent' => $intent, 'params' => (object) $params];
    }

    // ---- the dialogue engine's messages (protocol aiso.v2) ----

    public static function reply(string $text, array $actions = [], array $choices = []): string
    {
        return json_encode(['protocol' => 'aiso.v2', 'type' => 'reply', 'text' => $text, 'choices' => $choices, 'actions' => $actions], JSON_UNESCAPED_UNICODE);
    }

    /** @param array<int, array{0: string, 1?: array}> $calls tool name and arguments */
    public static function call(array ...$calls): string
    {
        return json_encode(['protocol' => 'aiso.v2', 'type' => 'call',
            'calls' => array_map(static fn (array $c) => ['tool' => $c[0], 'args' => (object) ($c[1] ?? [])], $calls)], JSON_UNESCAPED_UNICODE);
    }

    public static function ask(string $question, ?string $tool = null, array $args = [], array $missing = []): string
    {
        return json_encode(['protocol' => 'aiso.v2', 'type' => 'ask', 'question' => $question,
            'pending' => $tool === null ? null : ['tool' => $tool, 'args' => (object) $args, 'missing' => $missing]], JSON_UNESCAPED_UNICODE);
    }

    public static function act(string $action, array $args, string $reason = ''): array
    {
        return ['action' => $action, 'args' => $args, 'reason' => $reason];
    }
}
