<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-tenancy, step 6 of 6: team_id becomes NOT NULL. Rows written
 * without a team between step 3-5 and this step (an application that
 * was still running) are first assigned to the default team.
 */
return new class extends Migration
{
    public const TABLES = [
        'servers', 'domains', 'monitored_pages', 'alert_rules', 'alerts',
        'incidents', 'predictions', 'resource_dependencies',
        'webhook_endpoints', 'status_pages', 'api_tokens', 'audit_logs', 'notification_logs',
    ];

    public function up(): void
    {
        $teamId = DB::table('teams')->where('is_default', true)->value('id');

        foreach (self::TABLES as $table) {
            if (! $this->nullable($table)) {
                continue;
            }

            DB::table($table)->whereNull('team_id')->update(['team_id' => $teamId]);

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->unsignedBigInteger('team_id')->nullable(false)->change();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (Schema::hasColumn($table, 'team_id') && ! $this->nullable($table)) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->unsignedBigInteger('team_id')->nullable()->change();
                });
            }
        }
    }

    private function nullable(string $table): bool
    {
        foreach (Schema::getColumns($table) as $column) {
            if ($column['name'] === 'team_id') {
                return (bool) $column['nullable'];
            }
        }

        return false;
    }
};
