<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The production department (code PRODUCTION) keeps the import history in its panel. A department already named «تولید»
 * is adopted; nothing is created. The owner can give the code to another department in the panel's settings.
 */
return new class extends Migration
{
    public function up(): void
    {
        $dept = DB::table('department')->where('name', 'تولید')->whereNull('code')->orderBy('id')->first();
        if ($dept !== null) {
            DB::table('department')->where('id', $dept->id)->update(['code' => 'PRODUCTION']);
        }
    }

    public function down(): void
    {
        DB::table('department')->where('code', 'PRODUCTION')->update(['code' => null]);
    }
};
