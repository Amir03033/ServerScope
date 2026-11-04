<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Automatic deployments.
 *
 * - deploy_watches: the git checkouts an agent watches (SERVERSCOPE_DEPLOY_PATHS)
 *   and the commit it last saw; a new commit there is a deployment.
 * - github_connections: a repository whose webhook adds commit and pull
 *   request details, and optionally reports deployments itself.
 * - github_commits: what GitHub told us about a commit (title, author, PR).
 * - deployments.title: the commit or pull request title, when known.
 *
 * Additive only. Rollback: `php artisan migrate:rollback --step=1`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deploy_watches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('branch', 255)->nullable();
            $table->string('commit_sha', 64)->nullable();
            $table->string('error', 30)->nullable();
            $table->timestamp('changed_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['server_id', 'name']);
        });

        Schema::create('github_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->uuid('uuid')->unique();
            $table->string('repository', 200);
            $table->string('application', 100);
            $table->string('environment', 50)->default('production');
            $table->string('deploy_on', 20)->default('agent');
            $table->string('branch', 255)->nullable();
            $table->text('secret');
            $table->json('resources')->nullable();
            $table->timestamp('last_delivery_at')->nullable();
            $table->string('last_event', 50)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['team_id', 'repository']);
        });

        Schema::create('github_commits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('github_connection_id')->constrained()->cascadeOnDelete();
            $table->string('sha', 64);
            $table->string('title', 255)->nullable();
            $table->string('author', 100)->nullable();
            $table->string('url', 2048)->nullable();
            $table->unsignedInteger('pr_number')->nullable();
            $table->string('pr_url', 2048)->nullable();
            $table->timestamp('committed_at')->nullable();
            $table->timestamps();

            $table->unique(['github_connection_id', 'sha']);
            $table->index(['team_id', 'sha']);
        });

        Schema::table('deployments', function (Blueprint $table) {
            $table->string('title', 255)->nullable()->after('version');
        });
    }

    public function down(): void
    {
        Schema::table('deployments', fn (Blueprint $table) => $table->dropColumn('title'));
        Schema::dropIfExists('github_commits');
        Schema::dropIfExists('github_connections');
        Schema::dropIfExists('deploy_watches');
    }
};
