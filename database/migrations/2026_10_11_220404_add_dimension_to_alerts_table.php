<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alerts', function (Blueprint $table) {
            $table->string('dimension', 255)->nullable()->after('metric');
        });

        // Preserve existing disk alert mountpoints while normalizing
        // their metric identifier to "disk_percent".
        DB::table('alerts')
            ->where('metric', 'like', 'disk_percent:%')
            ->orderBy('id')
            ->chunkById(100, function ($alerts): void {
                foreach ($alerts as $alert) {
                    $mountpoint = substr(
                        $alert->metric,
                        strlen('disk_percent:'),
                    );

                    DB::table('alerts')
                        ->where('id', $alert->id)
                        ->update([
                            'metric' => 'disk_percent',
                            'dimension' => $mountpoint,
                        ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('alerts', function (Blueprint $table) {
            $table->dropColumn('dimension');
        });
    }
};
