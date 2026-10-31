<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Extra escalation e-mail addresses per team (max 5, set by team administrators). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            if (! Schema::hasColumn('teams', 'escalation_emails')) {
                $table->json('escalation_emails')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            if (Schema::hasColumn('teams', 'escalation_emails')) {
                $table->dropColumn('escalation_emails');
            }
        });
    }
};
