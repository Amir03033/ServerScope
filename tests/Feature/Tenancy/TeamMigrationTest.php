<?php

namespace Tests\Feature\Tenancy;

use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The tenancy migrations on a database that holds single-tenant data:
 * everything ends up in the default team, nothing changes for existing
 * users, and every step can be rolled back and run again.
 */
class TeamMigrationTest extends TestCase
{
    /**
     * Fresh schema before and after each test. Not DatabaseMigrations:
     * that rolls back every migration afterwards, and the down() of the
     * older migration 2026_10_28_100000 fails on MySQL (drops an index a
     * foreign key still needs) — unrelated to the tenancy migrations.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate:fresh');

        $this->beforeApplicationDestroyed(function (): void {
            $this->artisan('migrate:fresh');
            RefreshDatabaseState::$migrated = false;
        });
    }

    /** The six tenancy migrations. */
    private const FIRST_TENANCY_MIGRATION = '2026_10_29_100000_create_teams_tables';

    private const TABLES = ['servers', 'domains', 'monitored_pages', 'alert_rules', 'alerts', 'incidents', 'predictions', 'resource_dependencies', 'webhook_endpoints', 'status_pages', 'api_tokens', 'audit_logs', 'notification_logs'];

    public function test_existing_data_is_moved_into_the_default_team(): void
    {
        $this->artisan('migrate:rollback', ['--step' => $this->stepsBackToBeforeTenancy()])->assertSuccessful();
        $this->assertFalse(Schema::hasTable('teams'));

        $ids = $this->legacyData();

        $this->artisan('migrate')->assertSuccessful();

        $team = DB::table('teams')->where('is_default', true)->first();
        $this->assertNotNull($team);
        $this->assertSame(1, DB::table('teams')->count());

        foreach (self::TABLES as $table) {
            $this->assertSame(0, DB::table($table)->where('team_id', '!=', $team->id)->count(), $table);
            $this->assertGreaterThan(0, DB::table($table)->count(), "fixture for {$table}");
        }

        // Memberships: the oldest administrator owns the team, other
        // administrators stay administrators, everyone else is a member.
        $roles = DB::table('team_user')->where('team_id', $team->id)->pluck('role', 'user_id')->all();
        $this->assertSame(['owner', 'admin', 'member'], [$roles[$ids['admin']], $roles[$ids['admin2']], $roles[$ids['user']]]);
        $this->assertSame([$team->id], DB::table('users')->distinct()->pluck('current_team_id')->all());
        $this->assertSame(0, DB::table('users')->where('is_platform_admin', true)->count(), 'nobody becomes platform administrator');
        $this->assertSame([1, 1, 0], [(int) DB::table('users')->where('id', $ids['admin'])->value('is_admin'), (int) DB::table('users')->where('id', $ids['admin2'])->value('is_admin'), (int) DB::table('users')->where('id', $ids['user'])->value('is_admin')]);

        // team_id is required afterwards.
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('servers')->insert(['name' => 'x', 'hostname' => 'x', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_every_step_can_be_rolled_back_and_run_again(): void
    {
        $this->legacyDataAfterMigration();

        $this->artisan('migrate:rollback', ['--step' => $this->stepsBackToBeforeTenancy()])->assertSuccessful();
        $this->assertFalse(Schema::hasTable('teams'));
        $this->assertFalse(Schema::hasTable('team_user'));
        $this->assertFalse(Schema::hasColumn('users', 'current_team_id'));

        foreach (self::TABLES as $table) {
            $this->assertFalse(Schema::hasColumn($table, 'team_id'), $table);
        }

        $this->assertSame(1, DB::table('servers')->count(), 'rollback keeps the data');

        $this->artisan('migrate')->assertSuccessful();
        $this->assertSame(1, DB::table('teams')->count());
    }

    public function test_migrations_are_safe_to_run_again(): void
    {
        // Simulate a step that was interrupted after its DDL: running the
        // migration classes again must not fail or duplicate anything.
        foreach (glob(database_path('migrations/2026_10_29_1*.php')) as $file) {
            (require $file)->up();
        }

        $this->assertSame(1, DB::table('teams')->count());
        $this->assertSame(DB::table('users')->count(), DB::table('team_user')->count());
    }

    /** @return array<string, int> */
    private function legacyData(): array
    {
        $now = now();
        $user = fn (string $email, bool $admin) => DB::table('users')->insertGetId(['name' => $email, 'email' => $email, 'password' => 'x', 'is_admin' => $admin, 'created_at' => $now, 'updated_at' => $now]);
        $admin = $user('admin@example.test', true);
        $admin2 = $user('admin2@example.test', true);
        $plain = $user('user@example.test', false);

        $server = DB::table('servers')->insertGetId(['name' => 's', 'hostname' => 's', 'created_at' => $now, 'updated_at' => $now]);
        $domain = DB::table('domains')->insertGetId(['name' => 'example.com', 'created_at' => $now, 'updated_at' => $now]);
        $page = DB::table('monitored_pages')->insertGetId(['domain_id' => $domain, 'url' => 'https://example.com/', 'url_hash' => str_repeat('a', 64), 'created_at' => $now, 'updated_at' => $now]);
        $rule = DB::table('alert_rules')->where('metric', 'agent_offline')->value('id');
        $incident = DB::table('incidents')->insertGetId(['fingerprint' => 'f', 'source' => 'manual', 'title' => 't', 'opened_at' => $now, 'last_occurrence_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('alerts')->insert(['server_id' => $server, 'alert_rule_id' => $rule, 'metric' => 'agent_offline', 'observed_value' => 1, 'threshold' => 5, 'triggered_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('predictions')->insert(['resource_type' => 'server', 'resource_id' => $server, 'kind' => 'forecast', 'metric' => 'disk', 'reason' => 'r', 'detected_at' => $now, 'last_evaluated_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('resource_dependencies')->insert(['dependent_type' => 'page', 'dependent_id' => $page, 'dependency_type' => 'server', 'dependency_id' => $server, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('webhook_endpoints')->insert(['name' => 'w', 'url' => 'https://h.example.com', 'secret' => 'x', 'events' => '[]', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('status_pages')->insert(['name' => 'p', 'slug' => 'p', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('api_tokens')->insert(['user_id' => $admin, 'name' => 't', 'prefix' => 'ssk_', 'token_hash' => str_repeat('b', 64), 'abilities' => '["read"]', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('audit_logs')->insert(['user_id' => $admin, 'action' => 'a', 'description' => 'd']);
        DB::table('notification_logs')->insert(['incident_id' => $incident, 'kind' => 'opened', 'channel' => 'mail', 'created_at' => $now, 'updated_at' => $now]);

        return ['admin' => $admin, 'admin2' => $admin2, 'user' => $plain];
    }

    private function legacyDataAfterMigration(): void
    {
        $team = DB::table('teams')->where('is_default', true)->value('id');
        DB::table('servers')->insert(['team_id' => $team, 'name' => 's', 'hostname' => 's', 'created_at' => now(), 'updated_at' => now()]);
    }

    /**
     * Rolling back to just before the tenancy migrations: those six plus
     * every later migration (which may depend on teams).
     */
    private function stepsBackToBeforeTenancy(): int
    {
        return DB::table('migrations')->where('migration', '>=', self::FIRST_TENANCY_MIGRATION)->count();
    }
}
