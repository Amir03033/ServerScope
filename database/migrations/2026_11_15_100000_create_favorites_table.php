<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Personal favourites (servers, domains) per user and team. Unique per
 * user and resource; removed with the user, team or resource owner.
 * Additive only. Rollback: `php artisan migrate:rollback --step=1`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('favorites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('favoritable_type', 30);
            $table->unsignedBigInteger('favoritable_id');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['user_id', 'favoritable_type', 'favoritable_id'], 'favorites_unique');
            $table->index(['favoritable_type', 'favoritable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('favorites');
    }
};
