<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The AI service token and model can be entered in the owner console. The token is stored encrypted with the
 * application key (never in plain text) and is never sent back to the browser. Without a stored value the environment
 * (AISO_LLM_API_KEY, AISO_LLM_MODEL) still applies.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('system_setting', function (Blueprint $table) {
            $table->text('llm_api_key')->nullable();
            $table->string('llm_model', 100)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('system_setting', function (Blueprint $table) {
            $table->dropColumn(['llm_api_key', 'llm_model']);
        });
    }
};
