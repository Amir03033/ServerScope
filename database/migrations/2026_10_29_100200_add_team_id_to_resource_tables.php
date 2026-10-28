<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-tenancy, step 3 of 6: a nullable team_id on servers, domains, website pages, alert rules and alerts, filled
 * with the default team. The column becomes required in step 6. Rows are
 * updated in id ranges so locks stay short on large tables.
 */
return new class extends Migration
{
    private const TABLES = ['servers', 'domains', 'monitored_pages', 'alert_rules', 'alerts'];

    public function up(): void
    {
        $teamId = DB::table('teams')->where('is_default', true)->value('id');

        if ($teamId === null) {
            throw new RuntimeException('Default team missing: run the earlier tenancy migrations first.');
        }

        foreach (self::TABLES as $table) {
            if (! Schema::hasColumn($table, 'team_id')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->foreignId('team_id')->nullable()->after('id')->index()->constrained('teams');
                });
            }

            $max = (int) DB::table($table)->max('id');

            for ($from = 0; $from <= $max; $from += 5000) {
                DB::table($table)->whereNull('team_id')->whereBetween('id', [$from, $from + 4999])->update(['team_id' => $teamId]);
            }
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::TABLES) as $table) {
            if (Schema::hasColumn($table, 'team_id')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->dropForeign(['team_id']);
                    $blueprint->dropIndex(['team_id']);
                    $blueprint->dropColumn('team_id');
                });
            }
        }
    }
};
