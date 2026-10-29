<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Update this server to release X" (dashboard or automatic), and what
 * the updater on that server reported back.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('agent_update_requests')) {
            return;
        }

        Schema::create('agent_update_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams');
            $table->foreignId('server_id')->constrained('servers')->cascadeOnDelete();
            $table->foreignId('agent_release_id')->constrained('agent_releases');
            $table->string('from_version', 20)->nullable();
            $table->string('target_version', 20);
            // pending, in_progress, succeeded, rolled_back, rejected, failed, expired
            $table->string('status', 20)->default('pending');
            $table->string('reason', 40)->nullable();
            // manual, bulk or auto
            $table->string('source', 10);
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['server_id', 'status']);
            $table->index(['team_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_update_requests');
    }
};
