<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webhook_endpoints', function (Blueprint $table) {
            // generic = signed JSON (existing behaviour), discord = Discord
            // incoming webhook. For Discord the full webhook URL is a
            // credential: it is stored encrypted in `secret` and `url` only
            // holds a masked copy for display.
            $table->string('type', 20)->default('generic')->after('name');
            // Incident events below this severity are not sent (null = all).
            $table->string('min_severity', 10)->nullable()->after('events');
        });
    }

    public function down(): void
    {
        Schema::table('webhook_endpoints', function (Blueprint $table) {
            $table->dropColumn(['type', 'min_severity']);
        });
    }
};
