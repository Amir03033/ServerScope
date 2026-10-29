<?php

namespace Tests\Feature\Releases;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** The phase 3 migrations are additive, reversible and safe to run twice. */
class ReleaseMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATIONS = [
        '2026_11_01_100000_create_agent_releases_table',
        '2026_11_01_100100_create_agent_update_requests_table',
        '2026_11_01_100200_add_updater_fields_to_servers_table',
    ];

    private function migration(string $name): object
    {
        return require database_path("migrations/{$name}.php");
    }

    private function assertPresent(bool $present): void
    {
        $this->assertSame($present, Schema::hasTable('agent_releases'));
        $this->assertSame($present, Schema::hasTable('agent_update_requests'));

        foreach (['auto_update_enabled', 'updater_version', 'updater_state', 'updater_last_seen_at'] as $column) {
            $this->assertSame($present, Schema::hasColumn('servers', $column), $column);
        }
    }

    public function test_up_twice_down_twice_and_up_again(): void
    {
        $this->assertPresent(true);

        foreach (self::MIGRATIONS as $name) {
            $this->migration($name)->up();
        }

        $this->assertPresent(true);

        foreach (array_reverse(self::MIGRATIONS) as $name) {
            $this->migration($name)->down();
            $this->migration($name)->down();
        }

        $this->assertPresent(false);
        // Existing server columns are untouched.
        $this->assertTrue(Schema::hasColumn('servers', 'agent_version'));
        $this->assertTrue(Schema::hasColumn('servers', 'team_id'));

        foreach (self::MIGRATIONS as $name) {
            $this->migration($name)->up();
        }

        $this->assertPresent(true);
    }
}
