<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $user_id
 * @property string $title
 */
class ChatConversation extends Model
{
    protected $table = 'chat_conversation';
    public $timestamps = false;
    protected $fillable = ['user_id', 'title', 'created_at', 'updated_at'];

    /** The assistant's dialogue state is written only by ConversationStateStore (with its version check). */
    protected $hidden = ['dialogue_state'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime', 'updated_at' => 'datetime'];
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'conversation_id');
    }
}
