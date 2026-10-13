<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Departments of the company. Each one has a panel that only the owner sees; the owner sets its members, what each
 * department role may do, and which resources it keeps up to date. A department sends suggestions to the manager.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('department', function (Blueprint $table) {
            $table->id();
            $table->string('name', 200);
            $table->string('description', 1000)->nullable();
            $table->string('status', 20)->default('ACTIVE');
            $table->integer('sort_order')->default(0);
            $table->timestampTz('created_at');
        });

        Schema::create('department_member', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained('department')->cascadeOnDelete();
            $table->string('user_id', 64);
            $table->string('role', 20);
            $table->unique(['department_id', 'user_id']);
            $table->foreign('user_id')->references('id')->on('app_user');
        });

        // One row per permission a department role holds. No row = not allowed.
        Schema::create('department_role_permission', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained('department')->cascadeOnDelete();
            $table->string('role', 20);
            $table->string('permission', 40);
            $table->unique(['department_id', 'role', 'permission']);
        });

        Schema::create('department_suggestion', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained('department')->cascadeOnDelete();
            $table->string('author_id', 64);
            $table->string('resource_id', 64)->nullable();
            $table->string('title', 200);
            $table->string('body', 2000);
            $table->string('status', 20)->default('PENDING');
            $table->string('decided_by', 64)->nullable();
            $table->timestampTz('decided_at')->nullable();
            $table->string('manager_note', 1000)->nullable();
            $table->timestampTz('created_at');
            $table->index(['status', 'created_at']);
            $table->foreign('author_id')->references('id')->on('app_user');
            $table->foreign('resource_id')->references('id')->on('work_resource');
            $table->foreign('decided_by')->references('id')->on('app_user');
        });

        Schema::table('work_resource', function (Blueprint $table) {
            $table->foreignId('department_id')->nullable()->constrained('department')->nullOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('work_resource', function (Blueprint $table) {
            $table->dropConstrainedForeignId('department_id');
            $table->dropColumn('reviewed_at');
        });
        Schema::dropIfExists('department_suggestion');
        Schema::dropIfExists('department_role_permission');
        Schema::dropIfExists('department_member');
        Schema::dropIfExists('department');
    }
};
