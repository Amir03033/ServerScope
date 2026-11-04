<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cron and heartbeat monitoring, and chat channels beyond Discord.
 *
 * - heartbeats: a scheduled job that reports in on its own ping URL; when
 *   the ping does not arrive in time (or the job reports a failure) an
 *   incident is opened.
 * - heartbeat_pings: what each ping said (start, success, failure, exit
 *   code, duration, a redacted excerpt of the output).
 * - webhook_endpoints.target: the chat a Telegram bot posts to.
 *
 * Additive only. Rollback: `php artisan migrate:rollback --step=1`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('heartbeats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('server_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 100);
            $table->char('token', 32)->unique();
            $table->string('schedule_type', 10)->default('interval');
            $table->unsignedInteger('period_minutes')->nullable();
            $table->string('cron_expression', 100)->nullable();
            $table->string('timezone', 64)->default('UTC');
            $table->unsignedInteger('grace_minutes')->default(5);
            $table->string('severity', 10)->default('high');
            $table->string('status', 10)->default('new');
            $table->timestamp('last_ping_at')->nullable();
            $table->timestamp('last_start_at')->nullable();
            $table->timestamp('next_due_at')->nullable();
            $table->unsignedInteger('last_duration_ms')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'next_due_at']);
        });

        Schema::create('heartbeat_pings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('heartbeat_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 10);
            $table->smallInteger('exit_code')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->text('body')->nullable();
            $table->timestamp('received_at');

            $table->index(['heartbeat_id', 'received_at']);
        });

        Schema::table('webhook_endpoints', function (Blueprint $table) {
            $table->string('target', 100)->nullable()->after('url');
        });
    }

    public function down(): void
    {
        Schema::table('webhook_endpoints', function (Blueprint $table) {
            $table->dropColumn('target');
        });
        Schema::dropIfExists('heartbeat_pings');
        Schema::dropIfExists('heartbeats');
    }
};
