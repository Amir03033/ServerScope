<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Incident correlation, dependency mapping, MTTD and recovery
 * confirmation. Additive only: existing incidents keep working with the
 * new columns empty. Rollback: `php artisan migrate:rollback --step=1`
 * drops only what is added here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            // When the underlying problem began according to the evidence
            // (first failed check, alert start, last heartbeat). Used for MTTD.
            $table->timestamp('started_at')->nullable()->after('opened_at');
            // Root incident this one was grouped under (one level deep).
            $table->foreignId('parent_id')->nullable()->after('subject_id')->constrained('incidents')->nullOnDelete();
            // Why it was grouped: reason code, human explanation, evidence.
            $table->json('correlation')->nullable()->after('context');

            $table->index(['parent_id', 'status']);
        });

        // Every resource an incident affects or is caused by, including the
        // pages of a grouped website incident. Rows are never deleted when
        // a resource recovers, so the incident keeps its full picture.
        Schema::create('incident_impacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incident_id')->constrained()->cascadeOnDelete();
            $table->string('resource_type', 30);
            $table->unsignedBigInteger('resource_id');
            $table->string('role', 20)->default('affected');
            $table->string('status', 20)->default('failing');
            $table->string('failure_type', 30)->nullable();
            $table->string('message', 500)->nullable();
            $table->timestamp('first_failed_at')->nullable();
            $table->timestamp('last_failed_at')->nullable();
            $table->timestamp('recovered_at')->nullable();
            $table->json('details')->nullable();
            $table->timestamps();

            $table->unique(['incident_id', 'resource_type', 'resource_id'], 'incident_impact_unique');
            $table->index(['resource_type', 'resource_id', 'status']);
        });

        // User-defined relations between monitored resources: the
        // dependent needs the dependency to work ("website -> nginx").
        Schema::create('resource_dependencies', function (Blueprint $table) {
            $table->id();
            $table->string('dependent_type', 30);
            $table->unsignedBigInteger('dependent_id');
            $table->string('dependency_type', 30);
            $table->unsignedBigInteger('dependency_id');
            $table->string('label', 100)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['dependent_type', 'dependent_id', 'dependency_type', 'dependency_id'], 'resource_dependency_unique');
            $table->index(['dependency_type', 'dependency_id']);
        });

        // Recovery confirmation for server alert rules: the number of
        // consecutive good samples before an alert resolves (1 = as before).
        Schema::table('alert_rules', function (Blueprint $table) {
            $table->unsignedTinyInteger('recovery_samples')->default(1)->after('duration_minutes');
        });

        Schema::table('alerts', function (Blueprint $table) {
            $table->unsignedSmallInteger('recovery_streak')->default(0)->after('status');
        });

        // Per-resource notification preference and availability target.
        Schema::table('servers', function (Blueprint $table) {
            $table->boolean('notifications_enabled')->default(true)->after('notes');
            $table->decimal('sla_target', 6, 3)->nullable()->after('notifications_enabled');
        });

        Schema::table('domains', function (Blueprint $table) {
            $table->boolean('notifications_enabled')->default(true)->after('notes');
            $table->decimal('sla_target', 6, 3)->nullable()->after('notifications_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->dropColumn(['notifications_enabled', 'sla_target']);
        });

        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn(['notifications_enabled', 'sla_target']);
        });

        Schema::table('alerts', function (Blueprint $table) {
            $table->dropColumn('recovery_streak');
        });

        Schema::table('alert_rules', function (Blueprint $table) {
            $table->dropColumn('recovery_samples');
        });

        Schema::dropIfExists('resource_dependencies');
        Schema::dropIfExists('incident_impacts');

        Schema::table('incidents', function (Blueprint $table) {
            $table->dropIndex(['parent_id', 'status']);
            $table->dropConstrainedForeignId('parent_id');
            $table->dropColumn(['started_at', 'correlation']);
        });
    }
};
