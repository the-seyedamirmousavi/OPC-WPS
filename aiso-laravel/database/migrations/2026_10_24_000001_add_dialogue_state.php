<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The assistant's dialogue state lives with the conversation: the pending question (a tool and the parameters still
 * missing), a short rolling summary and the entities in focus. `state_version` guards it against two requests writing
 * at once. A message can carry the client's request id, so a retried message is answered from what was stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_conversation', function (Blueprint $table) {
            $table->text('dialogue_state')->nullable();
            $table->unsignedInteger('state_version')->default(0);
        });
        Schema::table('chat_message', function (Blueprint $table) {
            $table->string('request_id', 64)->nullable()->index();
            $table->unsignedBigInteger('run_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('chat_message', function (Blueprint $table) {
            $table->dropIndex(['request_id']);
            $table->dropColumn(['request_id', 'run_id']);
        });
        Schema::table('chat_conversation', function (Blueprint $table) {
            $table->dropColumn(['dialogue_state', 'state_version']);
        });
    }
};
