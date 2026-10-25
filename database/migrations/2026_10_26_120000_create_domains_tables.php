<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('domains', function (Blueprint $table) {
            $table->id();
            // Normalised ASCII (punycode) hostname, unique across the app.
            $table->string('name', 253)->unique();
            $table->string('scheme', 5)->default('https');
            $table->string('path', 255)->default('/');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            // Configuration
            $table->boolean('is_active')->default(true);
            $table->timestamp('paused_at')->nullable();
            $table->unsignedSmallInteger('check_interval_minutes')->default(5);
            $table->unsignedTinyInteger('timeout_seconds')->default(10);
            $table->unsignedTinyInteger('failure_threshold')->default(3);
            $table->unsignedTinyInteger('recovery_threshold')->default(2);
            $table->string('expected_status_codes', 100)->nullable();
            $table->string('expected_content', 255)->nullable();
            $table->boolean('follow_redirects')->default(true);
            $table->boolean('http_enabled')->default(true);
            $table->boolean('ssl_enabled')->default(true);
            $table->boolean('dns_enabled')->default(true);
            $table->boolean('expiry_enabled')->default(true);
            $table->json('ssl_warning_days')->nullable();
            $table->json('expiry_warning_days')->nullable();
            $table->timestamp('maintenance_starts_at')->nullable();
            $table->timestamp('maintenance_ends_at')->nullable();
            $table->string('maintenance_reason', 255)->nullable();

            // Aggregated health: healthy, warning, critical, unknown.
            $table->string('status', 20)->default('unknown');

            // HTTP state
            $table->string('http_status', 10)->default('unknown');
            $table->unsignedSmallInteger('http_consecutive_failures')->default(0);
            $table->unsignedSmallInteger('http_consecutive_successes')->default(0);
            $table->unsignedSmallInteger('http_last_status_code')->nullable();
            $table->float('http_last_response_ms')->nullable();
            $table->string('http_last_error', 500)->nullable();
            $table->timestamp('http_last_checked_at')->nullable();
            $table->timestamp('http_last_success_at')->nullable();
            $table->timestamp('http_next_check_at')->nullable();

            // TLS state
            $table->string('ssl_status', 20)->default('unknown');
            $table->timestamp('ssl_valid_from')->nullable();
            $table->timestamp('ssl_valid_to')->nullable();
            $table->string('ssl_issuer', 255)->nullable();
            $table->string('ssl_subject', 255)->nullable();
            $table->string('ssl_fingerprint', 128)->nullable();
            $table->string('ssl_message', 500)->nullable();
            $table->unsignedSmallInteger('ssl_warning_threshold')->nullable();
            $table->timestamp('ssl_last_checked_at')->nullable();
            $table->timestamp('ssl_next_check_at')->nullable();

            // DNS state
            $table->string('dns_status', 10)->default('unknown');
            $table->unsignedSmallInteger('dns_consecutive_failures')->default(0);
            $table->string('dns_message', 500)->nullable();
            $table->timestamp('dns_last_checked_at')->nullable();
            $table->timestamp('dns_last_changed_at')->nullable();
            $table->timestamp('dns_next_check_at')->nullable();

            // Registration / expiry (RDAP)
            $table->string('expiry_status', 20)->default('unknown');
            $table->timestamp('registration_expires_at')->nullable();
            $table->timestamp('registered_at')->nullable();
            $table->string('registrar', 255)->nullable();
            $table->string('registrable_domain', 253)->nullable();
            $table->string('expiry_source', 500)->nullable();
            $table->string('expiry_message', 500)->nullable();
            $table->unsignedSmallInteger('expiry_warning_threshold')->nullable();
            $table->timestamp('expiry_checked_at')->nullable();
            $table->timestamp('expiry_next_check_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'http_next_check_at']);
            $table->index('status');
        });

        Schema::create('domain_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domain_id')->constrained()->cascadeOnDelete();
            $table->string('type', 10);
            $table->string('result', 20);
            $table->boolean('success');
            $table->float('response_time_ms')->nullable();
            $table->unsignedSmallInteger('http_status_code')->nullable();
            $table->string('error_code', 40)->nullable();
            $table->string('message', 500)->nullable();
            $table->json('details')->nullable();
            $table->timestamp('checked_at');

            $table->index(['domain_id', 'type', 'checked_at']);
            $table->index('checked_at');
        });

        Schema::create('dns_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domain_id')->constrained()->cascadeOnDelete();
            $table->string('record_type', 10);
            $table->json('records');
            $table->char('hash', 64);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');

            $table->index(['domain_id', 'record_type', 'last_seen_at']);
        });

        Schema::create('dns_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domain_id')->constrained()->cascadeOnDelete();
            $table->string('record_type', 10);
            $table->json('previous');
            $table->json('current');
            $table->json('added');
            $table->json('removed');
            $table->timestamp('detected_at');

            $table->index(['domain_id', 'detected_at']);
            $table->index('detected_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dns_changes');
        Schema::dropIfExists('dns_snapshots');
        Schema::dropIfExists('domain_checks');
        Schema::dropIfExists('domains');
    }
};
