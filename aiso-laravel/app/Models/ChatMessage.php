<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $role user or assistant
 * @property string $content
 */
class ChatMessage extends Model
{
    protected $table = 'chat_message';
    public $timestamps = false;
    protected $fillable = ['conversation_id', 'role', 'content', 'created_at', 'choices', 'request_id', 'run_id', 'attachment'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime', 'choices' => 'array', 'attachment' => 'array'];
    }
}
