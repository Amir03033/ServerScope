<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI Incident Investigator: one row per analysis request. The unique
 * (incident_id, evidence_hash) prevents a second paid analysis of the same
 * evidence snapshot; status transitions guard against queue retries.
 * Usage columns support later cost reporting. Additive only.
 * Rollback: `php artisan migrate:rollback --step=1`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incident_analyses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('incident_id')->constrained()->cascadeOnDelete();
            // queued | running | completed | failed
            $table->string('status', 20)->default('queued');
            // auto | manual
            $table->string('trigger', 10);
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->char('evidence_hash', 64);
            $table->json('evidence')->nullable();
            $table->json('result')->nullable();
            $table->string('provider', 30)->nullable();
            $table->string('model', 100)->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->string('error_code', 30)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['incident_id', 'evidence_hash']);
            $table->index(['incident_id', 'created_at']);
            $table->index(['team_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incident_analyses');
    }
};
