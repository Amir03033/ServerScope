<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('metric_samples', function (Blueprint $table) {
            $table->id();

            $table->foreignId('server_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->decimal('cpu_percent', 5, 2)->nullable();

            $table->decimal('load_1', 8, 2)->nullable();
            $table->decimal('load_5', 8, 2)->nullable();
            $table->decimal('load_15', 8, 2)->nullable();

            $table->unsignedBigInteger('memory_total_bytes')->nullable();
            $table->unsignedBigInteger('memory_used_bytes')->nullable();
            $table->unsignedBigInteger('memory_available_bytes')->nullable();

            $table->unsignedBigInteger('swap_total_bytes')->nullable();
            $table->unsignedBigInteger('swap_used_bytes')->nullable();

            $table->json('disks')->nullable();

            $table->unsignedBigInteger('network_rx_bytes')->nullable();
            $table->unsignedBigInteger('network_tx_bytes')->nullable();

            $table->unsignedBigInteger('uptime_seconds')->nullable();

            $table->string('agent_version', 50)->nullable();
            $table->timestamp('collected_at');

            $table->timestamps();

            $table->index(['server_id', 'collected_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('metric_samples');
    }
};
