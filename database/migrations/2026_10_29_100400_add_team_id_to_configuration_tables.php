<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-tenancy, step 5 of 6: a nullable team_id on webhooks, status pages, API tokens, the audit log and the notification log, filled
 * with the default team. The column becomes required in step 6. Rows are
 * updated in id ranges so locks stay short on large tables.
 */
return new class extends Migration
{
    private const TABLES = ['webhook_endpoints', 'status_pages', 'api_tokens', 'audit_logs', 'notification_logs'];

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
