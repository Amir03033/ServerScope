<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('servers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('hostname', 255);
            $table->string('ip_address', 45)->nullable();
            $table->string('operating_system', 100)->nullable();
            $table->string('agent_version', 50)->nullable();
            $table->string('status', 20)->default('pending');
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'last_seen_at']);
            $table->index('hostname');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('servers');
    }
};
