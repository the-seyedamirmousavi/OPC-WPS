<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** An Excel file sent to the assistant, kept privately until it is imported or expires. */
class ChatUpload extends Model
{
    protected $table = 'chat_upload';
    public $timestamps = false;
    public $incrementing = false;
    protected $keyType = 'string';
    protected $fillable = ['id', 'user_id', 'conversation_id', 'file_name', 'path', 'size', 'created_at', 'expires_at', 'imported_at'];
    protected $hidden = ['path'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime', 'expires_at' => 'datetime', 'imported_at' => 'datetime'];
    }
}
