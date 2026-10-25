<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->string('environment', 20)->default('production')->after('operating_system');
            $table->text('notes')->nullable()->after('environment');
            $table->timestamp('maintenance_starts_at')->nullable()->after('last_seen_at');
            $table->timestamp('maintenance_ends_at')->nullable()->after('maintenance_starts_at');
            $table->string('maintenance_reason', 255)->nullable()->after('maintenance_ends_at');
            $table->softDeletes();

            $table->index('environment');
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropIndex(['environment']);
            $table->dropSoftDeletes();
            $table->dropColumn([
                'environment',
                'notes',
                'maintenance_starts_at',
                'maintenance_ends_at',
                'maintenance_reason',
            ]);
        });
    }
};
