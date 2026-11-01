<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Global Pulse Lite: one remote probe (platform infrastructure, not a
 * team resource) that re-checks a failing website before an incident is
 * opened. probe_checks holds the confirmation jobs and their results and
 * belongs to the domain's team. Additive only.
 * Rollback: `php artisan migrate:rollback --step=1`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('probes', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('location', 100)->nullable();
            // Public identifier sent in X-Probe-Key; the secret (HMAC key)
            // is stored encrypted with APP_KEY.
            $table->char('key', 32)->unique();
            $table->text('secret');
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_seen_at')->nullable();
            $table->string('version', 50)->nullable();
            $table->timestamps();
        });

        Schema::create('probe_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('probe_id')->constrained()->cascadeOnDelete();
            $table->foreignId('domain_id')->constrained()->cascadeOnDelete();
            // pending | completed | expired
            $table->string('status', 20)->default('pending');
            // confirmed | main_only | probe_only | not_reproduced | unavailable
            $table->string('outcome', 20)->nullable();
            $table->timestamp('requested_at');
            $table->timestamp('expires_at');
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->boolean('success')->nullable();
            $table->unsignedSmallInteger('http_status_code')->nullable();
            $table->float('response_time_ms')->nullable();
            $table->string('error_code', 50)->nullable();
            $table->string('message', 200)->nullable();
            $table->timestamps();

            $table->index(['probe_id', 'status', 'expires_at']);
            $table->index(['domain_id', 'requested_at']);
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('probe_checks');
        Schema::dropIfExists('probes');
    }
};
