<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incidents', function (Blueprint $table) {
            $table->id();

            // Identifies the underlying problem (e.g. "domain:5:http").
            $table->string('fingerprint', 191)->index();
            // Equal to fingerprint while the incident is unresolved, NULL
            // otherwise. The unique index guarantees one active incident
            // per problem, even under concurrent checks.
            $table->string('active_fingerprint', 191)->nullable()->unique();

            $table->string('subject_type', 50)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('source', 30);
            $table->string('severity', 10)->default('warning');
            $table->string('status', 20)->default('open');
            $table->string('title', 255);
            $table->text('description')->nullable();

            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('opened_at');
            $table->timestamp('last_occurrence_at');
            $table->unsignedInteger('occurrence_count')->default(1);
            $table->timestamp('acknowledged_at')->nullable();
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('resolution_notes')->nullable();
            $table->unsignedInteger('reopened_count')->default(0);
            $table->timestamp('escalated_at')->nullable();
            $table->timestamp('last_notified_at')->nullable();
            $table->json('context')->nullable();

            $table->timestamps();

            $table->index(['subject_type', 'subject_id']);
            $table->index(['status', 'severity']);
            $table->index('opened_at');
            $table->index(['fingerprint', 'resolved_at']);
        });

        Schema::create('incident_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incident_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('message')->nullable();
            $table->json('data')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['incident_id', 'created_at']);
        });

        Schema::table('alerts', function (Blueprint $table) {
            $table->foreignId('incident_id')->nullable()->after('alert_rule_id')->constrained()->nullOnDelete();
        });

        Schema::table('alert_rules', function (Blueprint $table) {
            $table->string('severity', 10)->default('warning')->after('operator');
        });

        // Agent disconnects are outages.
        DB::table('alert_rules')
            ->where('metric', 'agent_offline')
            ->update(['severity' => 'critical']);
    }

    public function down(): void
    {
        Schema::table('alert_rules', function (Blueprint $table) {
            $table->dropColumn('severity');
        });

        Schema::table('alerts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('incident_id');
        });

        Schema::dropIfExists('incident_events');
        Schema::dropIfExists('incidents');
    }
};
