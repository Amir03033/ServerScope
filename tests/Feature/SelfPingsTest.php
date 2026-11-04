<?php

namespace Tests\Feature;

use App\Jobs\PingFromQueue;
use App\Services\SelfPings\SelfPings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** ServerScope reports its own database, queue worker and cache on ping URLs from .env. */
class SelfPingsTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://serverscope.example/api/ping/';

    public function test_each_configured_check_reports_on_its_own_url(): void
    {
        Http::fake(['serverscope.example/*' => Http::response('OK')]);
        Queue::fake();
        config(['serverscope.self_pings' => ['database' => self::URL.'db', 'queue' => self::URL.'queue', 'cache' => self::URL.'cache/']]);

        SelfPings::run();

        Http::assertSent(fn ($r) => $r->url() === self::URL.'db' && $r->method() === 'GET');
        Http::assertSent(fn ($r) => $r->url() === self::URL.'cache' && $r->method() === 'GET');
        Http::assertSentCount(2);
        // The queue check only pings once a worker runs the job.
        Queue::assertPushed(PingFromQueue::class, fn ($job) => $job->url === self::URL.'queue');
    }

    public function test_a_worker_pings_and_a_stale_job_stays_quiet(): void
    {
        Http::fake(['serverscope.example/*' => Http::response('OK')]);

        (new PingFromQueue(self::URL.'queue'))->handle();
        (new PingFromQueue(self::URL.'queue', now()->subMinutes(10)->getTimestamp()))->handle();

        Http::assertSentCount(1);
    }

    public function test_a_database_that_does_not_answer_reports_a_failure(): void
    {
        Http::fake(['serverscope.example/*' => Http::response('OK')]);
        config(['serverscope.self_pings' => ['database' => self::URL.'db']]);
        DB::shouldReceive('select')->andThrow(new \RuntimeException('SQLSTATE[HY000] [2002] Connection refused'));

        SelfPings::run();

        Http::assertSent(fn ($r) => $r->url() === self::URL.'db/fail' && $r->method() === 'POST' && str_contains($r->body(), 'Connection refused'));
        Http::assertSentCount(1);
    }

    public function test_nothing_happens_without_urls_and_an_unreachable_monitor_breaks_nothing(): void
    {
        Http::preventStrayRequests();
        config(['serverscope.self_pings' => ['database' => null, 'queue' => '', 'cache' => 'not-a-url', 'other' => self::URL.'x']]);
        SelfPings::run();
        Http::assertNothingSent();

        Http::fake(fn () => throw new ConnectionException('timed out'));
        config(['serverscope.self_pings' => ['database' => self::URL.'db', 'cache' => self::URL.'cache']]);
        SelfPings::run();
        $this->assertTrue(true, 'no exception');
    }
}
