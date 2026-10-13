<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI agents are virtual users: rows of app_user with the role AGENT. They carry a standing job description and the
 * departments they may read; they run tasks that the creator (or the owner) gives them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_user', function (Blueprint $table) {
            $table->text('agent_instructions')->nullable();
            $table->string('agent_created_by', 64)->nullable();
            $table->text('agent_scope')->nullable();
        });

        Schema::create('agent_tasks', function (Blueprint $table) {
            $table->id();
            $table->string('agent_id', 64);
            $table->string('created_by', 64);
            $table->text('instruction');
            $table->string('status', 12)->default('PENDING');
            $table->text('result')->nullable();
            $table->string('error', 1000)->nullable();
            $table->unsignedBigInteger('run_id')->nullable();
            $table->timestampTz('created_at');
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->index(['agent_id', 'created_at']);
            $table->foreign('agent_id')->references('id')->on('app_user');
            $table->foreign('created_by')->references('id')->on('app_user');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_tasks');
        Schema::table('app_user', function (Blueprint $table) {
            $table->dropColumn(['agent_instructions', 'agent_created_by', 'agent_scope']);
        });
    }
};
