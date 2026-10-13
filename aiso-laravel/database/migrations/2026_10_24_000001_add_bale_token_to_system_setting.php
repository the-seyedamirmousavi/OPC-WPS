<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The Bale bot token, set by the owner in Settings. Stored encrypted; the environment token applies when this is empty. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('system_setting', function (Blueprint $table) {
            $table->text('bale_bot_token')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('system_setting', function (Blueprint $table) {
            $table->dropColumn('bale_bot_token');
        });
    }
};
