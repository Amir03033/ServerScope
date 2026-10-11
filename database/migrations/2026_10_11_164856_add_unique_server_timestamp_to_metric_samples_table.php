<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('metric_samples', function (Blueprint $table) {
            $table->unique(
                ['server_id', 'collected_at'],
                'metric_samples_server_timestamp_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('metric_samples', function (Blueprint $table) {
            $table->dropUnique(
                'metric_samples_server_timestamp_unique',
            );
        });
    }
};
