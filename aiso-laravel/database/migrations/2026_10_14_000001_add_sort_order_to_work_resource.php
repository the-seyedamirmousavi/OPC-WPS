<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Resources keep the order in which they were entered (the row order of the Resources sheet or of the plan builder), so the
 * timeline and the tables list them the way the manager wrote them. Resources saved before this column existed are
 * numbered by id, which is the order they were listed in until now.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_resource', function (Blueprint $table) {
            $table->integer('sort_order')->default(0);
        });
        $n = 1;
        foreach (DB::table('work_resource')->orderBy('id')->pluck('id') as $id) {
            DB::table('work_resource')->where('id', $id)->update(['sort_order' => $n++]);
        }
    }

    public function down(): void
    {
        Schema::table('work_resource', function (Blueprint $table) {
            $table->dropColumn('sort_order');
        });
    }
};
