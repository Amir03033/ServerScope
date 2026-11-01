<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deployments reported through the API (POST /api/v1/deployments) and the
 * monitored resources they affect. external_id (e.g. a CI run id) makes a
 * retried report without an Idempotency-Key land on the same row.
 * Additive only. Rollback: `php artisan migrate:rollback --step=1`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deployments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('application', 100);
            $table->string('environment', 50)->default('production');
            $table->string('version', 100)->nullable();
            $table->string('commit_sha', 64)->nullable();
            $table->timestamp('deployed_at');
            $table->string('source', 50)->nullable();
            $table->string('actor', 100)->nullable();
            $table->string('url', 2048)->nullable();
            $table->string('external_id', 191)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('api_token_id')->nullable()->constrained('api_tokens')->nullOnDelete();
            $table->timestamps();

            $table->index(['team_id', 'deployed_at']);
            $table->unique(['team_id', 'external_id']);
        });

        Schema::create('deployment_resources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deployment_id')->constrained()->cascadeOnDelete();
            $table->string('resource_type', 30);
            $table->unsignedBigInteger('resource_id');

            $table->unique(['deployment_id', 'resource_type', 'resource_id'], 'deployment_resource_unique');
            $table->index(['resource_type', 'resource_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deployment_resources');
        Schema::dropIfExists('deployments');
    }
};
