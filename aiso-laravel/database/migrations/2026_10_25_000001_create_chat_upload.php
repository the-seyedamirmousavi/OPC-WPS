<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Excel files sent to the assistant: kept privately on the server until they are imported or expire. A chat message can
 * carry an attachment (the preview table of such a file).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_upload', function (Blueprint $table) {
            $table->string('id', 64)->primary();
            $table->string('user_id', 64)->index();
            $table->unsignedBigInteger('conversation_id')->nullable();
            $table->string('file_name', 255);
            $table->string('path', 255);
            $table->unsignedInteger('size');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('imported_at')->nullable();
        });
        Schema::table('chat_message', function (Blueprint $table) {
            $table->text('attachment')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('chat_message', function (Blueprint $table) {
            $table->dropColumn('attachment');
        });
        Schema::dropIfExists('chat_upload');
    }
};
