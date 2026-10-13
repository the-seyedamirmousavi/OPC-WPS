<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Roles as data. The company defines its own roles (name, description, whether its people receive tasks, and the
 * capabilities it holds). The built-in roles are seeded here with what they did before, so nothing changes until the
 * company edits them. OWNER and AGENT are locked: the owner is an identity, and agents are virtual users.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_role', function (Blueprint $table) {
            $table->string('key', 20)->primary();
            $table->string('name', 80);
            $table->string('description', 500)->nullable();
            $table->boolean('receives_tasks')->default(false);
            $table->json('capabilities');
            $table->boolean('builtin')->default(false);
            $table->boolean('locked')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestampTz('created_at')->nullable();
        });

        $now = now();
        DB::table('app_role')->insert([
            ['key' => 'OWNER', 'name' => 'Owner', 'description' => null, 'receives_tasks' => false,
                'capabilities' => json_encode(['production.view', 'production.manage', 'users.view', 'users.manage', 'departments.manage']),
                'builtin' => true, 'locked' => true, 'sort_order' => 0, 'created_at' => $now],
            ['key' => 'MANAGER', 'name' => 'Manager', 'description' => null, 'receives_tasks' => false,
                'capabilities' => json_encode(['production.view', 'production.manage', 'users.view']),
                'builtin' => true, 'locked' => false, 'sort_order' => 1, 'created_at' => $now],
            ['key' => 'USER_1', 'name' => 'Executive user 1', 'description' => null, 'receives_tasks' => true,
                'capabilities' => json_encode([]), 'builtin' => true, 'locked' => false, 'sort_order' => 2, 'created_at' => $now],
            ['key' => 'USER_2', 'name' => 'Executive user 2', 'description' => null, 'receives_tasks' => true,
                'capabilities' => json_encode([]), 'builtin' => true, 'locked' => false, 'sort_order' => 3, 'created_at' => $now],
            ['key' => 'USER_3', 'name' => 'Executive user 3', 'description' => null, 'receives_tasks' => true,
                'capabilities' => json_encode([]), 'builtin' => true, 'locked' => false, 'sort_order' => 4, 'created_at' => $now],
            ['key' => 'AGENT', 'name' => 'AI agent', 'description' => null, 'receives_tasks' => false,
                'capabilities' => json_encode([]), 'builtin' => true, 'locked' => true, 'sort_order' => 5, 'created_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('app_role');
    }
};
