<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plan per team (free | pro | premium) and its payment state
 * (active | pending_payment). Every existing team becomes free/active.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            if (! Schema::hasColumn('teams', 'plan')) {
                $table->string('plan', 20)->default('free')->after('slug');
            }
            if (! Schema::hasColumn('teams', 'plan_status')) {
                $table->string('plan_status', 20)->default('active')->after('plan');
            }
        });
    }

    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            foreach (['plan_status', 'plan'] as $column) {
                if (Schema::hasColumn('teams', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
