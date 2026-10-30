<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per server: automatic updates (off by default) and the last check-in
 * of the updater (serverscope-updater).
 */
return new class extends Migration
{
    private const COLUMNS = ['auto_update_enabled', 'updater_version', 'updater_state', 'updater_last_seen_at'];

    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            if (! Schema::hasColumn('servers', 'auto_update_enabled')) {
                $table->boolean('auto_update_enabled')->default(false);
            }
            if (! Schema::hasColumn('servers', 'updater_version')) {
                $table->unsignedSmallInteger('updater_version')->nullable();
            }
            if (! Schema::hasColumn('servers', 'updater_state')) {
                $table->string('updater_state', 10)->nullable();
            }
            if (! Schema::hasColumn('servers', 'updater_last_seen_at')) {
                $table->timestamp('updater_last_seen_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        $existing = array_values(array_filter(self::COLUMNS, fn (string $column) => Schema::hasColumn('servers', $column)));

        if ($existing !== []) {
            Schema::table('servers', fn (Blueprint $table) => $table->dropColumn($existing));
        }
    }
};
