<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Domain names were unique platform-wide, so one customer could block a
 * domain of another. They are now unique per team.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->unique(['team_id', 'name'], 'domains_team_id_name_unique');
        });

        Schema::table('domains', function (Blueprint $table) {
            $table->dropUnique('domains_name_unique');
        });
    }

    public function down(): void
    {
        // Only possible while no two teams share a domain name.
        Schema::table('domains', function (Blueprint $table) {
            $table->unique('name', 'domains_name_unique');
        });

        Schema::table('domains', function (Blueprint $table) {
            $table->dropUnique('domains_team_id_name_unique');
        });
    }
};
