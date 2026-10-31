<?php

namespace Tests\Feature\Plans;

use App\Models\Domain;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DomainPathTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $team = Team::query()->create(['name' => 'D']);
        $u = User::factory()->create();
        $u->teams()->attach($team->id, ['role' => 'owner']);
        $u->forceFill(['current_team_id' => $team->id, 'is_admin' => true])->save();

        return $u;
    }

    private function payload(array $extra = []): array
    {
        return array_merge(['name' => 'amirs.life', 'scheme' => 'https', 'path' => '/', 'check_interval_minutes' => 5, 'timeout_seconds' => 10, 'failure_threshold' => 3, 'recovery_threshold' => 2,
            'http_enabled' => '1', 'ssl_enabled' => '1', 'dns_enabled' => '1', 'expiry_enabled' => '1', 'ssl_warning_days' => '30', 'expiry_warning_days' => '30'], $extra);
    }

    public function test_the_domain_name_typed_into_the_path_field_is_refused(): void
    {
        $admin = $this->admin();

        foreach (['amirs.life', '/amirs.life', 'www.amirs.life'] as $path) {
            $this->actingAs($admin)->post(route('domains.store'), $this->payload(['path' => $path]))->assertSessionHasErrors('path');
        }
        $this->assertSame(0, Domain::query()->count());
    }

    public function test_real_paths_still_work_and_the_checked_address_is_shown(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('domains.store'), $this->payload(['path' => '/health']))->assertSessionHasNoErrors();
        $domain = Domain::query()->sole();
        $this->assertSame('/health', $domain->path);

        $this->actingAs($admin)->get(route('domains.edit', $domain))->assertOk()->assertSee('https://amirs.life/health');
    }
}
