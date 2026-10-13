<?php

namespace App\AI;

/** What the chat gets back from the agent: the text, the actions waiting for confirmation, and where the data came from. */
final class AgentResponse
{
    /**
     * @param array<int, array> $pendingActions actions the user can confirm or cancel
     * @param array<int, array{slug: string, name: string}> $sources the departments whose data was used
     * @param array<int, array> $executed low-risk actions that already ran
     */
    public function __construct(
        public readonly string $text,
        public readonly array $pendingActions = [],
        public readonly array $sources = [],
        public readonly array $executed = [],
        public readonly bool $limited = false,
        /** what the agent did, step by step, for the page to show apart from the answer */
        public readonly array $trace = [],
        public readonly ?int $runId = null,
        public readonly string $model = '',
        /** short replies the user can send with one click, for a question the answer asks (at most four) */
        public readonly array $choices = [],
    ) {
    }
}
