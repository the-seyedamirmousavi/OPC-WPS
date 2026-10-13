<?php

namespace App\Services;

use App\AI\ActionExecutor;
use App\AI\AgentOrchestrator;
use App\AI\AgentResponse;
use App\AI\Dialogue\ConversationStateStore;
use App\AI\Dialogue\DialogueEngine;
use App\Exceptions\ApiException;
use App\Models\AiPendingAction;
use App\Models\AppUser;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The assistant chat: saved conversations per user. The answers come from the AI agent (App\AI), which reads only the
 * department data the user may see and can only propose actions; this class stores the conversation.
 *
 * With the dialogue engine, the conversation also keeps a small server-side state (pending question, summary, focus),
 * loaded and saved here around each turn. One turn per conversation runs at a time, and a message sent again with the
 * same request id is answered from what was stored, without running anything twice.
 */
class ChatService
{
    public const MAX_MESSAGES = 20;
    public const MAX_CHARS = 2000;

    public function __construct(
        private readonly AgentOrchestrator $agent,
        private readonly DialogueEngine $dialogue,
        private readonly ConversationStateStore $states,
        private readonly ActionExecutor $actions,
    ) {
    }

    /**
     * One turn of a saved conversation: the new message is added to the stored history (a new conversation when no id is
     * given), the agent answers, and both are stored. Nothing is stored when the AI service is unavailable.
     *
     * @return array{conversationId: int, reply: string, model: string, pending_actions: array, sources: array, executed: array, limited: bool}
     */
    public function send(AppUser $user, ?int $conversationId, string $text, ?string $requestId = null): array
    {
        $conversation = $conversationId === null ? null : self::own($user, $conversationId);
        if ($requestId !== null && ($replay = $this->replay($user, $requestId)) !== null) {
            return $replay;
        }

        // one turn at a time per conversation (and per person for a new one): a second one is refused, not interleaved
        $lock = Cache::lock('aiso:chat:' . $user->id . ':' . ($conversation?->id ?? 'new'), 330);
        if (!$lock->get()) {
            throw ApiException::conflict('The assistant is still answering your previous message in this conversation. Wait for it, then send again.');
        }
        try {
            $dialogue = config('aiso.agent.engine') === 'dialogue';
            if ($dialogue) {
                [$state, $version] = $this->states->load($conversation);
                $answer = $this->dialogue->turn($text, $user, $state);
            } else {
                $history = $conversation === null ? [] : $conversation->messages()->orderByDesc('id')->limit(self::MAX_MESSAGES)->get()->reverse()
                    ->map(fn (ChatMessage $m) => ['role' => $m->role, 'content' => $m->content])->values()->all();
                $answer = $this->agent->handle($text, $user, $history);
            }

            return DB::transaction(function () use ($user, $conversation, $text, $answer, $requestId, $dialogue, &$state, &$version) {
                $now = CarbonImmutable::now();
                $conversation ??= ChatConversation::query()->create([
                    'user_id' => $user->id, 'title' => mb_substr(trim($text), 0, 60), 'created_at' => $now, 'updated_at' => $now,
                ]);
                if ($dialogue) {
                    $this->states->save($conversation, $state, $version);
                }
                $conversation->messages()->create(['role' => 'user', 'content' => $text, 'created_at' => $now, 'request_id' => $requestId]);
                $conversation->messages()->create([
                    'role' => 'assistant', 'content' => $answer->text, 'created_at' => $now, 'choices' => $answer->choices ?: null,
                    'run_id' => $answer->runId,
                ]);
                $conversation->update(['updated_at' => $now]);

                return self::payload($conversation->id, $answer);
            });
        } finally {
            $lock->release();
        }
    }

    /**
     * The stored answer to a message already received with this request id (a retry after a lost response). The actions it
     * proposed are shown as they are now; nothing runs again.
     */
    private function replay(AppUser $user, string $requestId): ?array
    {
        $asked = ChatMessage::query()->join('chat_conversation', 'chat_conversation.id', '=', 'chat_message.conversation_id')
            ->where('chat_conversation.user_id', $user->id)->where('chat_message.request_id', $requestId)->where('chat_message.role', 'user')
            ->select('chat_message.*')->first();
        if ($asked === null) {
            return null;
        }
        $reply = ChatMessage::query()->where('conversation_id', $asked->conversation_id)->where('id', '>', $asked->id)
            ->where('role', 'assistant')->orderBy('id')->first();
        $pending = $reply?->run_id === null ? [] : AiPendingAction::query()->where('run_id', $reply->run_id)->orderBy('id')->get()
            ->map(fn (AiPendingAction $a) => $this->actions->view($a))->values()->all();

        return [
            'conversationId' => (int) $asked->conversation_id, 'reply' => (string) $reply?->content, 'model' => '',
            'pending_actions' => $pending, 'sources' => [], 'executed' => [], 'limited' => false, 'trace' => [],
            'choices' => $reply?->choices ?? [], 'replayed' => true,
        ];
    }

    private static function payload(int $conversationId, AgentResponse $answer): array
    {
        return [
            'conversationId' => $conversationId,
            'reply' => $answer->text,
            'model' => $answer->model,
            'pending_actions' => $answer->pendingActions,
            'sources' => $answer->sources,
            'executed' => $answer->executed,
            'limited' => $answer->limited,
            'trace' => $answer->trace,
            'choices' => $answer->choices,
        ];
    }

    /** @return array<int, array{id: int, title: string, updatedAt: string}> the person's own conversations, newest first */
    public function conversations(AppUser $user): array
    {
        return ChatConversation::query()->where('user_id', $user->id)->orderByDesc('updated_at')->orderByDesc('id')
            ->limit(100)->get()->map(fn (ChatConversation $c) => [
                'id' => $c->id,
                'title' => $c->title,
                'updatedAt' => $c->updated_at->utc()->format('Y-m-d\TH:i:s.v\Z'),
            ])->values()->all();
    }

    /** @return array{id: int, title: string, messages: array<int, array{role: string, content: string, choices: ?array}>} */
    public function conversation(AppUser $user, int $id): array
    {
        $c = self::own($user, $id);

        return ['id' => $c->id, 'title' => $c->title, 'messages' => $c->messages()->orderBy('id')->get()
            ->map(fn (ChatMessage $m) => ['role' => $m->role, 'content' => $m->content, 'choices' => $m->choices, 'attachment' => $m->attachment])->values()->all()];
    }

    public function delete(AppUser $user, int $id): void
    {
        self::own($user, $id)->delete();
    }

    /** A conversation is found only by its owner; for anyone else it does not exist. */
    private static function own(AppUser $user, int $id): ChatConversation
    {
        $c = ChatConversation::query()->where('user_id', $user->id)->find($id);
        if ($c === null) {
            throw ApiException::notFound("Conversation {$id} not found");
        }

        return $c;
    }
}
