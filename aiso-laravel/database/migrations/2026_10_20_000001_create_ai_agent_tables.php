<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The AI agent's log (every run and every step) and the actions waiting for the user's confirmation. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_runs', function (Blueprint $table) {
            $table->id();
            $table->string('user_id', 64);
            $table->text('question');
            $table->string('status', 12)->default('RUNNING');
            $table->unsignedSmallInteger('rounds')->default(0);
            $table->unsignedInteger('tokens_in')->default(0);
            $table->unsignedInteger('tokens_out')->default(0);
            $table->string('error', 1000)->nullable();
            $table->unsignedInteger('duration_ms')->default(0);
            $table->timestampTz('created_at');
            $table->index(['user_id', 'created_at']);
            $table->foreign('user_id')->references('id')->on('app_user');
        });

        Schema::create('ai_run_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('ai_runs')->cascadeOnDelete();
            $table->string('stage', 16);
            $table->string('department', 40)->nullable();
            $table->longText('input')->nullable();
            $table->longText('output')->nullable();
            $table->unsignedInteger('tokens_in')->default(0);
            $table->unsignedInteger('tokens_out')->default(0);
            $table->string('error', 1000)->nullable();
            $table->unsignedInteger('duration_ms')->default(0);
            $table->timestampTz('created_at');
        });

        Schema::create('ai_pending_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('ai_runs')->cascadeOnDelete();
            $table->string('user_id', 64);
            $table->string('department', 40);
            $table->string('operation', 60);
            $table->text('params');
            $table->string('reason', 300)->nullable();
            $table->string('risk', 10);
            $table->string('status', 12)->default('PENDING');
            $table->text('result')->nullable();
            $table->string('error', 1000)->nullable();
            $table->timestampTz('expires_at');
            $table->timestampTz('decided_at')->nullable();
            $table->timestampTz('created_at');
            $table->index(['user_id', 'status']);
            $table->foreign('user_id')->references('id')->on('app_user');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_pending_actions');
        Schema::dropIfExists('ai_run_steps');
        Schema::dropIfExists('ai_runs');
    }
};
