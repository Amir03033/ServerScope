<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_credentials', function (Blueprint $table) {
            $table->id();

            $table->foreignId('server_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('name', 100);
            $table->string('token_hash', 64)->unique();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();

            $table->timestamps();

            $table->index(['server_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_credentials');
    }
};
