<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Error and warning lines the agent read from log files it was told to
 * follow (SERVERSCOPE_LOG_FILES). fingerprint groups the same problem
 * (numbers, ids and quoted values left out) into one issue.
 * Kept for 14 days by default (serverscope.retention.log_entries_days).
 * Additive only. Rollback: `php artisan migrate:rollback --step=1`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('log_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->string('source', 50);
            $table->string('level', 10);
            $table->string('message', 1000);
            $table->text('context')->nullable();
            $table->char('fingerprint', 40);
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->nullable();

            $table->index(['team_id', 'occurred_at']);
            $table->index(['server_id', 'occurred_at']);
            $table->index(['team_id', 'fingerprint', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('log_entries');
    }
};
