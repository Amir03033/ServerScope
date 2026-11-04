<?php

namespace Tests\Feature;

use App\Services\SystemHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** The scheduler checks in on a cron-monitor URL from .env, when one is set. */
class SchedulerCheckInTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_scheduler_beat_checks_in_on_the_configured_url(): void
    {
        Http::fake(['https://serverscope.example/api/ping/*' => Http::response('OK')]);
        config(['serverscope.scheduler_ping_url' => 'https://serverscope.example/api/ping/'.str_repeat('a', 32)]);

        SystemHealth::beat();

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->url() === 'https://serverscope.example/api/ping/'.str_repeat('a', 32) && $request->method() === 'GET');
    }

    public function test_without_a_url_or_with_an_unreachable_monitor_the_scheduler_carries_on(): void
    {
        Http::preventStrayRequests();
        SystemHealth::beat();
        Http::assertNothingSent();

        Http::fake(fn () => throw new ConnectionException('timed out'));
        config(['serverscope.scheduler_ping_url' => 'https://down.example/api/ping/x']);
        SystemHealth::beat();

        $this->assertNotNull(cache(SystemHealth::HEARTBEAT_KEY));
    }
}
