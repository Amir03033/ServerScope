<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hourly and daily aggregates of server metrics (serverscope:rollup).
 *
 * One row per server, period, bucket, metric and dimension (disk mount).
 * count/sum/min/max merge exactly, so daily rows are derived from hourly
 * rows and an average is always sum / count. The unique key makes every
 * write an idempotent upsert; dimension is '' (never NULL) so the key
 * also holds on MySQL, where NULLs never collide.
 *
 * servers.metrics_rolled_up_until marks the end of the last closed hour
 * that was aggregated; readers use rollups before it and raw samples after.
 * servers.cpu_count is reported by agents that send it (logical CPUs).
 *
 * Additive only. Rollback: `php artisan migrate:rollback --step=1`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('metric_rollups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            // Denormalised from the server (servers never move teams):
            // tenant scoping and per-plan retention without a join.
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('period', 5); // hour | day
            $table->timestamp('bucket_start');
            $table->string('metric', 40);
            $table->string('dimension', 255)->default('');
            $table->unsignedInteger('count');
            $table->double('sum');
            $table->double('min');
            $table->double('max');
            $table->timestamps();

            $table->unique(['server_id', 'period', 'bucket_start', 'metric', 'dimension'], 'metric_rollups_unique');
            // Retention: delete old buckets per period without a table scan.
            $table->index(['period', 'bucket_start']);
            $table->index(['team_id', 'period', 'bucket_start']);
        });

        Schema::table('servers', function (Blueprint $table) {
            $table->timestamp('metrics_rolled_up_until')->nullable()->after('last_seen_at');
            $table->unsignedSmallInteger('cpu_count')->nullable()->after('agent_checksum');
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn(['metrics_rolled_up_until', 'cpu_count']);
        });

        Schema::dropIfExists('metric_rollups');
    }
};
