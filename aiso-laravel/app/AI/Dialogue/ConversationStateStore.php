<?php

namespace App\AI\Dialogue;

use App\Exceptions\ApiException;
use App\Models\ChatConversation;
use Illuminate\Support\Facades\DB;

/**
 * Loads and saves the dialogue state of a conversation. Saving checks the version that was loaded: when another request
 * changed the conversation in between, nothing is written and the request fails with a conflict (it can be retried).
 */
class ConversationStateStore
{
    /** @return array{0: DialogueState, 1: int} the state and the version it was read at */
    public function load(?ChatConversation $conversation): array
    {
        if ($conversation === null) {
            return [DialogueState::fresh(), 0];
        }

        return [DialogueState::load($conversation->dialogue_state), (int) $conversation->state_version];
    }

    /** Writes the state if the conversation is still at $version. Must run inside the turn's transaction. */
    public function save(ChatConversation $conversation, DialogueState $state, int $version): void
    {
        $changed = DB::table('chat_conversation')->where('id', $conversation->id)->where('state_version', $version)
            ->update(['dialogue_state' => $state->toJson(), 'state_version' => $version + 1]);
        if ($changed !== 1) {
            throw ApiException::conflict('This conversation was changed by another message at the same time. Send your message again.');
        }
    }
}
