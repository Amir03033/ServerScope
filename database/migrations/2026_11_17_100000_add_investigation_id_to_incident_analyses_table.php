<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NEXUS Intelligence phase 2: an AI analysis made from a deterministic
 * investigation (contract nexus.investigation.v1) records which run it
 * interpreted. Nullable: legacy analyses (and analyses whose run was
 * removed by retention) have none; the analysed input stays in
 * `evidence` either way. Additive only.
 * Rollback: `php artisan migrate:rollback --step=1`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incident_analyses', function (Blueprint $table) {
            $table->foreignId('investigation_id')->nullable()->after('incident_id')->constrained('investigations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('incident_analyses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('investigation_id');
        });
    }
};
