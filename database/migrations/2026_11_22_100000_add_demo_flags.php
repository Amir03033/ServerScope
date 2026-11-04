<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The public read-only demo: one shared demo team per language and its
 * demo user. Both are marked so the demo can be kept apart everywhere
 * (no outgoing traffic, no writes, not in platform figures).
 *
 * Additive only. Rollback: `php artisan migrate:rollback --step=1`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->boolean('is_demo')->default(false)->index();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_demo')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('is_demo'));
        Schema::table('teams', function (Blueprint $table) {
            $table->dropIndex(['is_demo']);
            $table->dropColumn('is_demo');
        });
    }
};
