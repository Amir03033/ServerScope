<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One-time tokens with which install.sh connects a new server to a team.
 * Only the SHA-256 hash is stored; the plaintext is shown once.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('enrollment_tokens')) {
            return;
        }

        Schema::create('enrollment_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('label', 100)->nullable();
            $table->char('token_hash', 64)->unique();
            // First characters of the plaintext, for recognising it in lists.
            $table->string('prefix', 12);
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->string('used_ip', 45)->nullable();
            // The server created with this token.
            $table->foreignId('server_id')->nullable()->constrained('servers')->nullOnDelete();
            // What the installing host reported (hostname, os, arch, version).
            $table->json('reported')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['team_id', 'used_at', 'revoked_at', 'expires_at'], 'enrollment_tokens_team_state_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enrollment_tokens');
    }
};
