<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a remote probe is, for the NEXUS globe. Optional: a probe without
 * coordinates keeps working and is listed on the globe page as "not
 * placed". Set with `serverscope:probe:create --lat= --lon=` or
 * `serverscope:probe:locate`. Additive only.
 * Rollback: `php artisan migrate:rollback --step=1`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('probes', function (Blueprint $table) {
            $table->decimal('latitude', 8, 5)->nullable()->after('location');
            $table->decimal('longitude', 8, 5)->nullable()->after('latitude');
        });
    }

    public function down(): void
    {
        Schema::table('probes', function (Blueprint $table) {
            $table->dropColumn(['latitude', 'longitude']);
        });
    }
};
