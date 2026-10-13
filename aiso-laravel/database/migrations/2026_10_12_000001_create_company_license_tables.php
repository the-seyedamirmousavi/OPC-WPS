<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Licensing for the software vendor. One installation belongs to one company (singleton row, id = 1) and carries its
 * licence terms: plan, validity period, limits and feature switches. Without a row the installation is unrestricted,
 * so existing installations keep working until the vendor defines the company.
 *
 * license_event is the vendor's own log. It is deliberately separate from audit_event, which owners and managers read.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->string('installation_id', 36);
            $table->string('name', 200);
            $table->string('legal_name', 200)->nullable();
            $table->string('contact_name', 200)->nullable();
            $table->string('contact_phone', 50)->nullable();
            $table->string('contact_email', 200)->nullable();
            $table->string('notes', 2000)->nullable();

            $table->string('plan', 20)->default('UNLIMITED');
            $table->string('status', 20)->default('ACTIVE');
            $table->string('suspended_reason', 500)->nullable();
            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->unsignedInteger('grace_days')->default(0);

            $table->unsignedInteger('max_users')->nullable();
            $table->unsignedInteger('max_active_projects')->nullable();
            $table->boolean('allow_llm')->default(true);
            $table->boolean('allow_bale')->default(true);

            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');
        });

        Schema::create('license_event', function (Blueprint $table) {
            $table->id();
            $table->timestampTz('occurred_at')->index();
            $table->string('action', 60);
            $table->string('detail', 2000)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('license_event');
        Schema::dropIfExists('company');
    }
};
