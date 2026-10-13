<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Human resources is a department with the code HR: its panel holds the user management. A department already named
 * «منابع انسانی» is adopted as the HR department; otherwise one is created with the default permissions. The PANEL_VIEW
 * permission lets a department role open the panel; every existing department gets it for heads and members, and nothing
 * else the owner had set is changed.
 */
return new class extends Migration
{
    private const HR_NAME = 'منابع انسانی';

    public function up(): void
    {
        Schema::table('department', function (Blueprint $table) {
            $table->string('code', 20)->nullable()->unique();
        });

        $adopt = DB::table('department')->where('name', self::HR_NAME)->orderBy('id')->first();
        if ($adopt !== null) {
            DB::table('department')->where('id', $adopt->id)->update(['code' => 'HR']);
            $hrId = null;
        } else {
            $hrId = DB::table('department')->insertGetId([
                'name' => self::HR_NAME,
                'description' => null,
                'status' => 'ACTIVE',
                'sort_order' => 0,
                'created_at' => now(),
                'code' => 'HR',
            ]);
            foreach ([['HEAD', 'PANEL_VIEW'], ['HEAD', 'RESOURCES_MANAGE'], ['HEAD', 'SUGGESTIONS_SUBMIT'],
                ['MEMBER', 'PANEL_VIEW'], ['MEMBER', 'SUGGESTIONS_SUBMIT']] as [$role, $permission]) {
                DB::table('department_role_permission')->insert([
                    'department_id' => $hrId, 'role' => $role, 'permission' => $permission,
                ]);
            }
        }

        // every department that existed before the new permission: heads and members may open the panel
        $existing = DB::table('department')->when($hrId !== null, fn ($q) => $q->where('id', '!=', $hrId))->pluck('id');
        foreach ($existing as $id) {
            foreach (['HEAD', 'MEMBER'] as $role) {
                DB::table('department_role_permission')->insert([
                    'department_id' => $id, 'role' => $role, 'permission' => 'PANEL_VIEW',
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('department_role_permission')->where('permission', 'PANEL_VIEW')->delete();
        Schema::table('department', function (Blueprint $table) {
            $table->dropUnique(['code']);
            $table->dropColumn('code');
        });
    }
};
