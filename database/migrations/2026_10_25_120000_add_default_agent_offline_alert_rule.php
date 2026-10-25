<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const NAME = 'Agent offline';

    public function up(): void
    {
        $exists = DB::table('alert_rules')
            ->where('metric', 'agent_offline')
            ->exists();

        if ($exists) {
            return;
        }

        DB::table('alert_rules')->insert([
            'name' => self::NAME,
            'metric' => 'agent_offline',
            'threshold' => 5,
            'operator' => '>=',
            'duration_minutes' => 1,
            'is_enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Only remove the default rule if it never produced alerts.
        $ruleIds = DB::table('alert_rules')
            ->where('metric', 'agent_offline')
            ->where('name', self::NAME)
            ->pluck('id');

        foreach ($ruleIds as $ruleId) {
            if (! DB::table('alerts')->where('alert_rule_id', $ruleId)->exists()) {
                DB::table('alert_rules')->where('id', $ruleId)->delete();
            }
        }
    }
};
