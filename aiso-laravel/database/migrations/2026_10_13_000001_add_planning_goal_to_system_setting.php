<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The planning goal (what the schedule is optimised for) is a project-wide setting. PRIORITY is the behaviour every
 * existing installation already had, so nothing changes until a manager picks another goal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('system_setting', function (Blueprint $table) {
            $table->string('planning_goal', 20)->default('PRIORITY');
            $table->string('planning_focus_project', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('system_setting', function (Blueprint $table) {
            $table->dropColumn(['planning_goal', 'planning_focus_project']);
        });
    }
};
