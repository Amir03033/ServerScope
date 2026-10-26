<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Service checks reported by the agent, extended metrics (disk I/O,
 * network errors, process count), agent integrity information and
 * predictive-monitoring preferences. Additive only; older agents keep
 * working and simply leave the new columns empty.
 * Rollback: `php artisan migrate:rollback --step=1`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('metric_samples', function (Blueprint $table) {
            $table->unsignedBigInteger('disk_read_bytes')->nullable()->after('disks');
            $table->unsignedBigInteger('disk_write_bytes')->nullable()->after('disk_read_bytes');
            $table->unsignedBigInteger('network_errors_in')->nullable()->after('network_tx_bytes');
            $table->unsignedBigInteger('network_errors_out')->nullable()->after('network_errors_in');
            $table->unsignedBigInteger('network_drops_in')->nullable()->after('network_errors_out');
            $table->unsignedBigInteger('network_drops_out')->nullable()->after('network_drops_in');
            $table->unsignedInteger('process_count')->nullable()->after('uptime_seconds');
        });

        Schema::table('servers', function (Blueprint $table) {
            $table->char('agent_checksum', 64)->nullable()->after('agent_version');
            $table->boolean('predictive_alerts_enabled')->default(true)->after('sla_target');
            $table->string('anomaly_sensitivity', 10)->default('medium')->after('predictive_alerts_enabled');
        });

        Schema::create('service_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            // systemd unit or process name as configured on the agent
            $table->string('name', 100);
            // nginx, apache, mysql, postgresql, redis, php-fpm or custom
            $table->string('kind', 20)->default('custom');
            $table->string('display_name', 100)->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->boolean('alerts_enabled')->default(true);
            $table->string('severity', 10)->default('high');
            $table->unsignedTinyInteger('failure_threshold')->default(2);
            // unknown, running, starting, stopped, failed, not_found, stale
            $table->string('status', 20)->default('unknown');
            $table->string('state_detail', 100)->nullable();
            $table->unsignedSmallInteger('consecutive_failures')->default(0);
            $table->timestamp('last_reported_at')->nullable();
            $table->timestamp('last_running_at')->nullable();
            $table->timestamp('failing_since')->nullable();
            $table->timestamps();

            $table->unique(['server_id', 'name']);
        });

        // Status transitions only (not every report), so history stays small.
        Schema::create('service_check_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_check_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20);
            $table->string('previous_status', 20)->nullable();
            $table->string('detail', 100)->nullable();
            $table->timestamp('recorded_at');

            $table->index(['service_check_id', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_check_events');
        Schema::dropIfExists('service_checks');

        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn(['agent_checksum', 'predictive_alerts_enabled', 'anomaly_sensitivity']);
        });

        Schema::table('metric_samples', function (Blueprint $table) {
            $table->dropColumn(['disk_read_bytes', 'disk_write_bytes', 'network_errors_in', 'network_errors_out', 'network_drops_in', 'network_drops_out', 'process_count']);
        });
    }
};
