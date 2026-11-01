<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Incident Memory: what was learned from an incident (cause, symptoms,
 * resolution, outcome). One row per incident (unique incident_id); version
 * guards against concurrent edits. Additive only.
 * Rollback: `php artisan migrate:rollback --step=1`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incident_learnings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('incident_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('cause_category', 30);
            // hypothesis | confirmed
            $table->string('cause_status', 20)->default('hypothesis');
            $table->text('root_cause');
            $table->json('symptoms')->nullable();
            $table->text('resolution_steps')->nullable();
            // successful | unsuccessful | unresolved
            $table->string('outcome', 20)->default('unresolved');
            $table->unsignedInteger('time_to_resolve_minutes')->nullable();
            $table->foreignId('deployment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->index(['team_id', 'cause_category']);
            $table->index(['team_id', 'outcome']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incident_learnings');
    }
};
