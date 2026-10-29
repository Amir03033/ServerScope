<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Signed agent releases (platform-wide, no team). Rows are only created
 * by serverscope:release:import after the signature has been verified;
 * the files live in storage/app/agent-releases/<version>/.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('agent_releases')) {
            return;
        }

        Schema::create('agent_releases', function (Blueprint $table) {
            $table->id();
            $table->string('version', 20)->unique();
            $table->unsignedSmallInteger('version_major');
            $table->unsignedSmallInteger('version_minor');
            $table->unsignedSmallInteger('version_patch');
            $table->string('filename', 60);
            $table->char('sha256', 64);
            $table->unsignedInteger('size');
            $table->char('commit', 40);
            $table->char('key_id', 16);
            $table->timestamp('released_at');
            $table->unsignedSmallInteger('min_updater_version');
            // The exact signed bytes and the signature (base64).
            $table->text('manifest');
            $table->string('signature', 120);
            $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('withdrawn_at')->nullable();
            $table->foreignId('withdrawn_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('withdraw_reason', 255)->nullable();
            $table->timestamps();
            $table->index(['withdrawn_at', 'version_major', 'version_minor', 'version_patch'], 'agent_releases_available_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_releases');
    }
};
