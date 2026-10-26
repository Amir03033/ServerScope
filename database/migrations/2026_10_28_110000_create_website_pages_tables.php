<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Website monitoring: individual pages of a monitored domain, their check
 * history, content changes and discovery runs. A domain with pages is a
 * "website"; its existing single-URL HTTP check keeps working unchanged.
 * Rollback: `php artisan migrate:rollback --step=1` drops these tables
 * and the domains.discovery_settings column (page history is lost).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->json('discovery_settings')->nullable()->after('expiry_warning_days');
        });

        Schema::create('monitored_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domain_id')->constrained()->cascadeOnDelete();
            $table->string('url', 2048);
            // sha256 of the normalised URL: unique per website.
            $table->char('url_hash', 64);
            $table->string('title', 255)->nullable();
            // manual, sitemap, link or robots
            $table->string('source', 20)->default('manual');
            // approved, pending (discovered, awaiting review) or rejected
            $table->string('approval_status', 20)->default('approved');
            $table->unsignedTinyInteger('depth')->nullable();

            // Configuration
            $table->boolean('is_active')->default(true);
            $table->timestamp('paused_at')->nullable();
            $table->unsignedSmallInteger('check_interval_minutes')->default(5);
            $table->unsignedTinyInteger('timeout_seconds')->default(10);
            $table->unsignedTinyInteger('failure_threshold')->default(2);
            $table->unsignedTinyInteger('recovery_threshold')->default(2);
            $table->string('expected_status_codes', 100)->nullable();
            $table->unsignedInteger('max_response_ms')->nullable();
            $table->boolean('follow_redirects')->default(true);
            $table->string('expected_final_url', 2048)->nullable();
            $table->string('keyword_present', 255)->nullable();
            $table->string('keyword_absent', 255)->nullable();
            $table->json('header_assertions')->nullable();
            // Response headers whose values are tracked for drift.
            $table->json('monitored_headers')->nullable();
            $table->boolean('content_monitoring')->default(false);
            $table->string('content_selector', 255)->nullable();
            $table->json('content_exclusions')->nullable();
            $table->boolean('alerts_enabled')->default(true);
            $table->string('alert_severity', 10)->default('high');
            $table->boolean('notify_content_changes')->default(false);
            $table->decimal('sla_target', 6, 3)->nullable();

            // State
            $table->string('status', 10)->default('unknown');
            $table->string('last_failure_type', 30)->nullable();
            $table->unsignedSmallInteger('consecutive_failures')->default(0);
            $table->unsignedSmallInteger('consecutive_successes')->default(0);
            $table->unsignedSmallInteger('last_status_code')->nullable();
            $table->float('last_response_ms')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->string('last_final_url', 2048)->nullable();
            $table->boolean('tls_valid')->nullable();
            $table->char('content_fingerprint', 64)->nullable();
            // Normalised text of the monitored content (capped) for diffs.
            $table->mediumText('content_snapshot')->nullable();
            $table->json('header_snapshot')->nullable();
            $table->timestamp('content_changed_at')->nullable();
            $table->timestamp('failing_since')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->timestamp('next_check_at')->nullable();
            $table->timestamp('discovered_at')->nullable();
            $table->timestamp('last_seen_in_discovery_at')->nullable();

            $table->timestamps();

            $table->unique(['domain_id', 'url_hash']);
            $table->index(['domain_id', 'approval_status', 'status']);
            $table->index(['is_active', 'approval_status', 'next_check_at']);
        });

        Schema::create('page_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('monitored_page_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('domain_id');
            // up, degraded or down
            $table->string('result', 10);
            $table->boolean('success');
            $table->string('failure_type', 30)->nullable();
            $table->unsignedSmallInteger('http_status_code')->nullable();
            $table->float('response_time_ms')->nullable();
            $table->string('error_code', 40)->nullable();
            $table->string('message', 500)->nullable();
            $table->boolean('content_changed')->default(false);
            $table->json('details')->nullable();
            $table->timestamp('checked_at');

            $table->index(['monitored_page_id', 'checked_at']);
            $table->index(['domain_id', 'checked_at']);
            $table->index('checked_at');
        });

        Schema::create('page_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('monitored_page_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('domain_id');
            // content or headers (configuration drift)
            $table->string('kind', 20)->default('content');
            $table->char('previous_fingerprint', 64)->nullable();
            $table->char('new_fingerprint', 64);
            // minor or significant
            $table->string('significance', 20)->default('minor');
            $table->float('change_ratio')->nullable();
            $table->string('summary', 500)->nullable();
            $table->json('diff')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('detected_at');

            $table->index(['monitored_page_id', 'detected_at']);
            $table->index(['domain_id', 'detected_at']);
        });

        Schema::create('page_discovery_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domain_id')->constrained()->cascadeOnDelete();
            $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();
            // queued, running, completed, partial or failed
            $table->string('status', 20)->default('queued');
            $table->json('settings')->nullable();
            $table->unsignedInteger('requests_made')->default(0);
            $table->unsignedInteger('urls_found')->default(0);
            $table->unsignedInteger('pages_added')->default(0);
            $table->unsignedInteger('pages_seen')->default(0);
            $table->unsignedInteger('urls_skipped')->default(0);
            $table->json('sources')->nullable();
            $table->json('errors')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['domain_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_discovery_runs');
        Schema::dropIfExists('page_changes');
        Schema::dropIfExists('page_checks');
        Schema::dropIfExists('monitored_pages');

        Schema::table('domains', function (Blueprint $table) {
            $table->dropColumn('discovery_settings');
        });
    }
};
