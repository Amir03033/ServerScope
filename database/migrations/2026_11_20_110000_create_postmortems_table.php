<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Postmortems: the written review of one incident. Facts (times, impact,
 * cause, timeline) are read from the incident and Incident Memory when the
 * page is shown; only what people write is stored here. share_token makes
 * a read-only public link; null means not shared.
 * Additive only. Rollback: `php artisan migrate:rollback --step=1`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('postmortems', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('incident_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('summary')->nullable();
            $table->text('impact')->nullable();
            $table->text('went_well')->nullable();
            $table->text('went_wrong')->nullable();
            $table->json('action_items')->nullable();
            $table->string('share_token', 64)->nullable()->unique();
            $table->timestamp('shared_at')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('postmortems');
    }
};
