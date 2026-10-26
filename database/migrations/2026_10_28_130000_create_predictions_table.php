<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Results of statistical anomaly detection and capacity forecasting.
 * One active row per resource/kind/metric/dimension (active_key); rows
 * are resolved, not deleted, so the history stays explainable.
 * Rollback: `php artisan migrate:rollback --step=1`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('predictions', function (Blueprint $table) {
            $table->id();
            $table->string('resource_type', 30);
            $table->unsignedBigInteger('resource_id');
            // anomaly or forecast
            $table->string('kind', 20);
            $table->string('metric', 40);
            $table->string('dimension', 255)->nullable();
            $table->string('status', 20)->default('active');
            $table->string('active_key', 191)->nullable()->unique();
            $table->string('severity', 10)->default('info');
            $table->unsignedTinyInteger('severity_rank')->default(0);
            $table->string('confidence', 10)->nullable();
            $table->double('observed_value')->nullable();
            $table->double('baseline_value')->nullable();
            $table->double('baseline_spread')->nullable();
            $table->double('score')->nullable();
            $table->double('slope_per_day')->nullable();
            $table->double('r_squared')->nullable();
            $table->double('threshold')->nullable();
            $table->timestamp('eta_at')->nullable();
            $table->timestamp('eta_low_at')->nullable();
            $table->timestamp('eta_high_at')->nullable();
            $table->unsignedSmallInteger('horizon_days')->nullable();
            $table->timestamp('window_start')->nullable();
            $table->timestamp('window_end')->nullable();
            $table->unsignedInteger('sample_count')->default(0);
            $table->text('reason');
            $table->json('details')->nullable();
            $table->timestamp('detected_at');
            $table->timestamp('last_evaluated_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['resource_type', 'resource_id', 'status']);
            $table->index(['status', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('predictions');
    }
};
