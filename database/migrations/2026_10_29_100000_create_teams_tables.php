<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-tenancy, step 1 of 6: teams, memberships with a role, the
 * current team per user and the platform administrator flag.
 * Every step checks what already exists, so an interrupted run (MySQL
 * cannot roll back DDL) can simply be started again.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('teams')) {
            Schema::create('teams', function (Blueprint $table) {
                $table->id();
                $table->string('name', 100);
                $table->string('slug', 60)->unique();
                // The team that holds all data from before multi-tenancy.
                $table->boolean('is_default')->default(false)->index();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('team_user')) {
            Schema::create('team_user', function (Blueprint $table) {
                $table->id();
                $table->foreignId('team_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                // owner, admin or member
                $table->string('role', 20)->default('member');
                $table->timestamps();
                $table->unique(['team_id', 'user_id']);
                $table->index('user_id');
            });
        }

        if (! Schema::hasColumn('users', 'current_team_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->foreignId('current_team_id')->nullable()->after('is_admin')->constrained('teams')->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('users', 'is_platform_admin')) {
            Schema::table('users', function (Blueprint $table) {
                $table->boolean('is_platform_admin')->default(false)->after('is_admin');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'current_team_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropConstrainedForeignId('current_team_id');
            });
        }

        if (Schema::hasColumn('users', 'is_platform_admin')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('is_platform_admin');
            });
        }

        Schema::dropIfExists('team_user');
        Schema::dropIfExists('teams');
    }
};
