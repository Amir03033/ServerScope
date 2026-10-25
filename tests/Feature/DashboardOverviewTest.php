<?php

namespace Tests\Feature;

use App\Models\Domain;
use App\Models\Server;
use App\Models\User;
use App\Services\Incidents\IncidentManager;
use App\Services\Incidents\Signal;
use App\Services\Reports\DashboardOverview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardOverviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_overview_uses_real_monitoring_data(): void
    {
        Server::create(['name' => 'Up', 'hostname' => 'up.example.test', 'last_seen_at' => now()]);
        Server::create(['name' => 'Down', 'hostname' => 'down.example.test', 'last_seen_at' => now()->subHour()]);
        $healthy = Domain::create(['name' => 'ok.example.com']);
        $healthy->forceFill(['status' => 'healthy', 'ssl_valid_to' => now()->addDays(20), 'ssl_status' => 'expiring'])->save();
        $broken = Domain::create(['name' => 'down.example.com']);
        $broken->forceFill(['status' => 'critical', 'http_status' => 'down', 'expiry_status' => 'verified', 'registration_expires_at' => now()->addDays(5)])->save();
        // Unverified data never appears as an upcoming expiry.
        Domain::create(['name' => 'unknown.example.com'])->forceFill(['expiry_status' => 'unavailable', 'registration_expires_at' => null])->save();

        $broken->checks()->create(['type' => 'http', 'result' => 'down', 'success' => false, 'checked_at' => now()->subHour()]);
        $healthy->checks()->create(['type' => 'http', 'result' => 'up', 'success' => true, 'response_time_ms' => 120, 'checked_at' => now()->subMinutes(30)]);
        $healthy->checks()->create(['type' => 'http', 'result' => 'up', 'success' => true, 'response_time_ms' => 80, 'checked_at' => now()->subMinutes(20)]);
        $healthy->checks()->create(['type' => 'http', 'result' => 'up', 'success' => true, 'checked_at' => now()->subMinutes(10)]);

        app(IncidentManager::class)->trigger(new Signal('t:1', 'http', 'critical', 'down.example.com is onbereikbaar', null, $broken));

        $data = app(DashboardOverview::class)->data();

        $this->assertSame(['total' => 2, 'online' => 1, 'offline' => 1, 'maintenance' => 0, 'pending' => 0], $data['servers']);
        $this->assertSame(3, $data['domains']['total']);
        $this->assertSame(1, $data['domains']['critical']);
        $this->assertSame(1, $data['domains']['http_problems']);
        $this->assertSame(1, $data['incidents']['critical']);
        $this->assertSame(['registration', 'ssl'], $data['expirations']->pluck('type')->all());
        $this->assertSame(75.0, $data['trends']['uptime_24h']);
        $this->assertSame(4, $data['trends']['checks_24h']);
        $this->assertSame(100.0, collect($data['trends']['response'])->pluck('value')->filter()->first());

        $this->actingAs(User::factory()->create())->get(route('dashboard'))
            ->assertOk()
            ->assertSee(['Actieve incidenten', 'down.example.com is onbereikbaar', 'Verloopt binnenkort', 'Recente DNS-wijzigingen', '75,00%']);
    }

    public function test_global_search(): void
    {
        $this->get(route('search', ['q' => 'alpha']))->assertRedirect('/login');

        $user = User::factory()->create();
        Server::create(['name' => 'Webserver Alpha', 'hostname' => 'alpha.example.test']);
        $domain = Domain::create(['name' => 'xn--bcher-kva.de']);
        $incident = app(IncidentManager::class)->trigger(new Signal('t:2', 'manual', 'warning', 'Alpha traag', null));

        $this->actingAs($user)->get(route('search', ['q' => 'alpha']))
            ->assertOk()->assertSee('Webserver Alpha')->assertSee('Alpha traag');

        $this->actingAs($user)->get(route('search', ['q' => 'bücher.de']))->assertSee('bücher.de');
        $this->actingAs($user)->get(route('search', ['q' => '#'.$incident->id]))->assertSee('Alpha traag');
        $this->actingAs($user)->get(route('search', ['q' => 'x']))->assertSee('minimaal twee tekens');
        $this->actingAs($user)->get(route('search', ['q' => '%%']))->assertSee('Niets gevonden');
    }
}
