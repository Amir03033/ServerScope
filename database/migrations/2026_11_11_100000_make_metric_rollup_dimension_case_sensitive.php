<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * metric_rollups.dimension holds disk mount paths, which are case
 * sensitive (/Data and /data are different mounts). The default MySQL
 * collation (utf8mb4_unicode_ci) compares case-insensitively and ignores
 * trailing spaces, so both would collide on the unique key. Use a binary
 * collation on MySQL/MariaDB; SQLite and PostgreSQL already compare
 * strings byte for byte. No data changes.
 * Rollback: `php artisan migrate:rollback --step=1`.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->collate(match (DB::connection()->getDriverName()) {
            // NO PAD: trailing spaces count too (MySQL 8.0.17+).
            'mysql' => 'utf8mb4_0900_bin',
            'mariadb' => 'utf8mb4_bin',
            default => null,
        });
    }

    public function down(): void
    {
        $this->collate(in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)
            ? (string) config('database.connections.'.DB::connection()->getName().'.collation', 'utf8mb4_unicode_ci')
            : null);
    }

    private function collate(?string $collation): void
    {
        if ($collation === null) {
            return;
        }

        Schema::table('metric_rollups', function (Blueprint $table) use ($collation) {
            $table->string('dimension', 255)->default('')->collation($collation)->change();
        });
    }
};
