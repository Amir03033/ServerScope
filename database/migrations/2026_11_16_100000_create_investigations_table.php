<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NEXUS Intelligence: deterministic investigations of an incident (no AI,
 * no external service). One row per run; a completed run is never
 * rewritten, a rerun adds a row. The result stores codes and parameters
 * (rendered in the reader's language), never translated sentences.
 * Separate from incident_analyses, which belongs to the AI Incident
 * Investigator. Additive only.
 * Rollback: `php artisan migrate:rollback --step=1`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('investigations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('incident_id')->constrained()->cascadeOnDelete();
            // queued | running | completed | partial | failed | superseded
            $table->string('status', 20)->default('queued');
            // opened | reopened | resolved | manual
            $table->string('trigger', 20);
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('pipeline_version');
            // The moment the evidence describes ("now" of the run).
            $table->timestamp('as_of')->nullable();
            $table->timestamp('window_start')->nullable();
            $table->timestamp('window_end')->nullable();
            $table->json('scope')->nullable();
            $table->json('result')->nullable();
            $table->json('summary')->nullable();
            $table->char('input_digest', 64)->nullable();
            $table->string('error_code', 40)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['incident_id', 'created_at']);
            $table->index(['team_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('investigations');
    }
};
