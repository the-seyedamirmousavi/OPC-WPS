<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The owner decides what the assistant may do. A master switch (off: the assistant only reads), and a policy per action:
 * disabled, confirmed by the requester, or approved by the owner. An action that needs the owner's approval waits in the
 * pending actions with its approval kind; the owner's decision is recorded with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('system_setting', function (Blueprint $table) {
            $table->boolean('ai_writes_enabled')->default(true);
        });

        Schema::create('ai_action_policies', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 20);
            $table->string('action', 60);
            $table->string('mode', 10);
            $table->timestampTz('updated_at');
            $table->unique(['kind', 'action']);
        });

        Schema::table('ai_pending_actions', function (Blueprint $table) {
            $table->string('approval', 10)->default('SELF');
            $table->string('approved_by', 64)->nullable();
            $table->string('decision_note', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ai_pending_actions', function (Blueprint $table) {
            $table->dropColumn(['approval', 'approved_by', 'decision_note']);
        });
        Schema::dropIfExists('ai_action_policies');
        Schema::table('system_setting', function (Blueprint $table) {
            $table->dropColumn('ai_writes_enabled');
        });
    }
};
