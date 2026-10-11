<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AISO schema. Replaces the Flyway scripts V1–V4 of the Spring backend. Business identifiers (Resource_ID, Operation_ID,
 * ...) are the primary keys, as in the original.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });

        Schema::create('app_user', function (Blueprint $table) {
            $table->string('id', 64)->primary();
            $table->string('full_name', 200);
            $table->string('user_role', 20);
            $table->string('messenger_id', 100)->nullable();
            $table->boolean('active')->default(true);
            $table->string('contact_info', 300)->nullable();
            $table->string('password_hash', 100);
            $table->boolean('must_change_password')->default(false);
            $table->timestampTz('created_at');
        });

        Schema::create('work_resource', function (Blueprint $table) {
            $table->string('id', 64)->primary();
            $table->string('name', 200);
            $table->string('resource_type', 100)->nullable();
            $table->string('responsible_user_id', 64)->nullable();
            $table->integer('capacity')->default(1);
            $table->string('status', 20)->default('ACTIVE');
            $table->string('description', 1000)->nullable();
            $table->foreign('responsible_user_id')->references('id')->on('app_user');
        });

        Schema::create('item', function (Blueprint $table) {
            $table->string('id', 64)->primary();
            $table->string('name', 200);
            $table->double('quantity')->default(1);
            $table->string('unit', 30)->nullable();
            $table->string('supply_type', 50)->nullable();
            $table->string('related_operation_id', 64)->nullable();
            $table->string('description', 1000)->nullable();
        });

        Schema::create('project', function (Blueprint $table) {
            $table->string('id', 64)->primary();
            $table->string('name', 200);
            $table->integer('priority')->default(1);
            $table->timestampTz('due_date')->nullable();
            $table->string('status', 20)->default('ACTIVE');
            $table->string('description', 1000)->nullable();
            $table->timestampTz('created_at');
        });

        Schema::create('operation', function (Blueprint $table) {
            $table->string('id', 64)->primary();
            $table->string('name', 300);
            $table->string('project_id', 64);
            $table->string('item_id', 64)->nullable();
            $table->string('resource_id', 64);
            $table->string('responsible_user_id', 64)->nullable();
            $table->double('preparation_time')->default(0);
            $table->double('transport_time')->default(0);
            $table->double('setup_time')->default(0);
            $table->double('direct_time')->default(0);
            $table->string('status', 20)->default('NOT_READY');
            $table->string('assigned_user_id', 64)->nullable();
            $table->string('block_reason', 1000)->nullable();
            $table->string('status_before_block', 20)->nullable();
            $table->integer('progress_percent')->default(0);
            $table->timestampTz('planned_start')->nullable();
            $table->timestampTz('planned_end')->nullable();
            $table->timestampTz('assigned_at')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->boolean('completion_approved')->default(false);
            $table->string('completion_approved_by', 64)->nullable();
            $table->string('cancel_reason', 1000)->nullable();
            $table->string('description', 1000)->nullable();
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');
            $table->unsignedBigInteger('version')->default(0);

            $table->index('status');
            $table->index('assigned_user_id');
            $table->index('project_id');
            $table->foreign('project_id')->references('id')->on('project');
            $table->foreign('item_id')->references('id')->on('item');
            $table->foreign('resource_id')->references('id')->on('work_resource');
            $table->foreign('responsible_user_id')->references('id')->on('app_user');
            $table->foreign('assigned_user_id')->references('id')->on('app_user');
        });

        Schema::create('predecessor', function (Blueprint $table) {
            $table->id();
            $table->string('operation_id', 64);
            $table->string('predecessor_id', 64);
            $table->string('dependency_type', 20)->default('FINISH_TO_START');
            $table->string('start_condition', 300)->nullable();
            $table->boolean('mandatory')->default(true);
            $table->string('description', 500)->nullable();
            $table->unique(['operation_id', 'predecessor_id']);
            $table->foreign('operation_id')->references('id')->on('operation');
            $table->foreign('predecessor_id')->references('id')->on('operation');
        });

        Schema::create('system_setting', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->string('project_id', 64)->nullable();
            $table->string('project_name', 200)->nullable();
            $table->string('owner_id', 64)->nullable();
            $table->string('manager_id', 64)->nullable();
            $table->string('messenger_platform', 30)->default('IN_APP');
            $table->string('system_status', 20)->default('ACTIVE');
            $table->string('data_version', 50)->nullable();
            $table->integer('configuration_version')->default(1);
            $table->string('assignment_mode', 20)->default('ALGORITHM');
            $table->integer('max_active_tasks_per_user')->default(3);
            $table->string('language', 5)->default('en');
            $table->boolean('require_completion_approval')->default(false);
            $table->timestampTz('updated_at');
        });

        Schema::create('audit_event', function (Blueprint $table) {
            $table->string('event_id', 36)->primary();
            $table->timestampTz('occurred_at')->index();
            $table->string('actor_id', 64)->nullable();
            $table->string('action_name', 60);
            $table->string('entity_type', 60);
            $table->string('entity_id', 64)->nullable();
            $table->string('previous_value', 4000)->nullable();
            $table->string('new_value', 4000)->nullable();
            $table->string('reason', 1000)->nullable();
            $table->string('project_id', 64)->nullable();
            $table->index(['entity_type', 'entity_id']);
        });

        Schema::create('notification', function (Blueprint $table) {
            $table->id();
            $table->string('recipient_id', 64);
            $table->string('kind', 40);
            $table->string('message', 1000);
            $table->string('related_operation_id', 64)->nullable();
            $table->timestampTz('created_at');
            $table->timestampTz('read_at')->nullable();
            $table->string('delivery_status', 20)->default('SENT');
            $table->string('delivery_error', 500)->nullable();
            $table->index(['recipient_id', 'created_at']);
            $table->foreign('recipient_id')->references('id')->on('app_user');
        });

        Schema::create('operation_report', function (Blueprint $table) {
            $table->id();
            $table->string('operation_id', 64);
            $table->string('user_id', 64);
            $table->string('report_type', 30);
            $table->integer('progress_percent')->nullable();
            $table->string('note', 2000)->nullable();
            $table->timestampTz('created_at');
            $table->index('operation_id');
            $table->foreign('operation_id')->references('id')->on('operation');
        });

        Schema::create('assignment_run', function (Blueprint $table) {
            $table->string('id', 36)->primary();
            $table->string('requested_mode', 20);
            $table->string('effective_mode', 20);
            $table->boolean('fallback')->default(false);
            $table->string('model', 100)->nullable();
            $table->string('summary', 2000)->nullable();
            $table->string('warnings', 4000)->nullable();
            $table->string('unassigned', 4000)->nullable();
            $table->string('requested_by', 64);
            $table->timestampTz('created_at');
            $table->unsignedBigInteger('duration_ms')->default(0);
            $table->unsignedBigInteger('input_tokens')->nullable();
            $table->unsignedBigInteger('output_tokens')->nullable();
        });

        Schema::create('assignment_proposal', function (Blueprint $table) {
            $table->id();
            $table->string('run_id', 36);
            $table->string('operation_id', 64);
            $table->string('user_id', 64);
            $table->string('source', 20);
            $table->string('reason', 2000)->nullable();
            $table->timestampTz('planned_start')->nullable();
            $table->timestampTz('planned_end')->nullable();
            $table->string('status', 20)->default('PENDING');
            $table->string('decision_note', 500)->nullable();
            $table->timestampTz('created_at');
            $table->timestampTz('decided_at')->nullable();
            $table->string('decided_by', 64)->nullable();
            $table->index('status');
            $table->foreign('run_id')->references('id')->on('assignment_run');
            $table->foreign('operation_id')->references('id')->on('operation');
            $table->foreign('user_id')->references('id')->on('app_user');
        });

        Schema::create('import_log', function (Blueprint $table) {
            $table->id();
            $table->timestampTz('imported_at');
            $table->string('actor_id', 64);
            $table->string('file_name', 300)->nullable();
            $table->string('file_format', 20)->nullable();
            $table->string('status', 20);
            $table->integer('error_count')->default(0);
            $table->string('summary', 2000)->nullable();
            $table->text('details')->nullable();
        });

        // Manager FORCE: one row per operation; replacing it overwrites the row, removing it deletes the row.
        Schema::create('schedule_force', function (Blueprint $table) {
            $table->string('operation_id', 64)->primary();
            $table->timestampTz('forced_start')->nullable();
            $table->string('forced_user_id', 64)->nullable();
            $table->string('reason', 500)->nullable();
            $table->string('created_by', 64);
            $table->timestampTz('created_at');
            $table->foreign('operation_id')->references('id')->on('operation');
            $table->foreign('forced_user_id')->references('id')->on('app_user');
        });

        // Reference plan: projected start/end of every unfinished operation, saved on FORCE changes and re-optimisation.
        Schema::create('plan_baseline', function (Blueprint $table) {
            $table->string('operation_id', 64)->primary();
            $table->timestampTz('planned_start');
            $table->timestampTz('planned_end');
            $table->timestampTz('saved_at');
            $table->foreign('operation_id')->references('id')->on('operation');
        });
    }

    public function down(): void
    {
        foreach ([
            'plan_baseline', 'schedule_force', 'import_log', 'assignment_proposal', 'assignment_run',
            'operation_report', 'notification', 'audit_event', 'system_setting', 'predecessor', 'operation',
            'project', 'item', 'work_resource', 'app_user', 'sessions',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
