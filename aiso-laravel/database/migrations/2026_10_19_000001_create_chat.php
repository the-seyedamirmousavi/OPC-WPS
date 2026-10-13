<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Saved conversations with the AI assistant. A conversation belongs to one user and nobody else reads it. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_conversation', function (Blueprint $table) {
            $table->id();
            $table->string('user_id', 64);
            $table->string('title', 120);
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');
            $table->index(['user_id', 'updated_at']);
            $table->foreign('user_id')->references('id')->on('app_user');
        });

        Schema::create('chat_message', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('chat_conversation')->cascadeOnDelete();
            $table->string('role', 12);
            $table->text('content');
            $table->timestampTz('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_message');
        Schema::dropIfExists('chat_conversation');
    }
};
