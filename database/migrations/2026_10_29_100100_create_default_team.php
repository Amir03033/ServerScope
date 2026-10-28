<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Multi-tenancy, step 2 of 6: the default team and a membership for
 * every existing user. The oldest administrator owns the team, other
 * administrators stay administrators, everyone else becomes a member.
 * Nobody becomes platform administrator (set with
 * `php artisan serverscope:create-admin --platform`).
 */
return new class extends Migration
{
    public function up(): void
    {
        $teamId = DB::table('teams')->where('is_default', true)->value('id');

        if ($teamId === null) {
            $teamId = DB::table('teams')->insertGetId([
                'name' => config('app.name', 'ServerScope'),
                'slug' => DB::table('teams')->where('slug', 'standaard')->exists() ? 'standaard-'.bin2hex(random_bytes(3)) : 'standaard',
                'is_default' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $ownerId = DB::table('users')->where('is_admin', true)->orderBy('id')->value('id');

        DB::table('users')->orderBy('id')->chunkById(500, function ($users) use ($teamId, $ownerId): void {
            $existing = DB::table('team_user')->where('team_id', $teamId)->whereIn('user_id', $users->pluck('id'))->pluck('user_id')->all();
            $rows = [];

            foreach ($users as $user) {
                if (! in_array($user->id, $existing, false)) {
                    $rows[] = [
                        'team_id' => $teamId,
                        'user_id' => $user->id,
                        'role' => (int) $user->id === (int) $ownerId ? 'owner' : ($user->is_admin ? 'admin' : 'member'),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
            }

            if ($rows !== []) {
                DB::table('team_user')->insert($rows);
            }
        });

        DB::table('users')->whereNull('current_team_id')->update(['current_team_id' => $teamId]);
    }

    public function down(): void
    {
        $teamId = DB::table('teams')->where('is_default', true)->value('id');

        if ($teamId === null) {
            return;
        }

        DB::table('users')->where('current_team_id', $teamId)->update(['current_team_id' => null]);
        DB::table('team_user')->where('team_id', $teamId)->delete();
        DB::table('teams')->where('id', $teamId)->delete();
    }
};
