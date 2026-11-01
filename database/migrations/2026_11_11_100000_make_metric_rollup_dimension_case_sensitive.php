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
 * collation on MySQL (utf8mb4_0900_bin) or MariaDB (utf8mb4_nopad_bin),
 * detected from the server; SQLite and PostgreSQL already compare
 * strings byte for byte. No data changes.
 * Rollback: `php artisan migrate:rollback --step=1`.
 */
return new class extends Migration
{
    public function up(): void
    {
        $connection = DB::connection();

        // Decided by the server, not the driver name: a "mysql" connection
        // can point at MariaDB, which has no utf8mb4_0900_* collations.
        // Both choices are binary and NO PAD (trailing spaces count too).
        $this->collate(match (true) {
            ! in_array($connection->getDriverName(), ['mysql', 'mariadb'], true) => null,
            $connection->isMaria() => 'utf8mb4_nopad_bin', // MariaDB 10.2+
            default => 'utf8mb4_0900_bin',                 // MySQL 8.0.17+
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
