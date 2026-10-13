<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The quick replies the assistant offered under an answer, so they are still there when the conversation is reopened. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_message', function (Blueprint $table) {
            $table->json('choices')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('chat_message', function (Blueprint $table) {
            $table->dropColumn('choices');
        });
    }
};
