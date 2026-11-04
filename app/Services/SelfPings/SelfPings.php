<?php

namespace App\Services\SelfPings;

use App\Jobs\PingFromQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * ServerScope checks its own building blocks every minute and reports each
 * on a scheduled job's ping URL from .env, so a database, queue worker or
 * cache that stops is noticed without any cron line on the server:
 *   SERVERSCOPE_DB_PING_URL     the database answers a query
 *   SERVERSCOPE_QUEUE_PING_URL  a queued job is picked up by a worker
 *   SERVERSCOPE_CACHE_PING_URL  the cache stores and returns a value
 * A check that fails calls URL/fail with the error, which opens an incident
 * at once. Never throws: a failing check must not break the scheduler.
 */
class SelfPings
{
    public const CHECKS = ['database', 'queue', 'cache'];

    /** @return array<string, string> the checks that have a usable URL */
    public static function configured(): array
    {
        return array_filter(
            array_map(fn ($url) => rtrim((string) $url, '/'), (array) config('serverscope.self_pings', [])),
            fn (string $url, string $check) => in_array($check, self::CHECKS, true) && (str_starts_with($url, 'https://') || str_starts_with($url, 'http://')),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    public static function run(): void
    {
        foreach (self::configured() as $check => $url) {
            try {
                match ($check) {
                    'database' => self::database($url),
                    'queue' => PingFromQueue::dispatch($url),
                    'cache' => self::cache($url),
                };
            } catch (Throwable $e) {
                self::fail($url, $e->getMessage());
            }
        }
    }

    public static function success(string $url): void
    {
        rescue(fn () => Http::timeout(5)->connectTimeout(3)->get($url), report: false);
    }

    public static function fail(string $url, string $reason): void
    {
        rescue(fn () => Http::timeout(5)->connectTimeout(3)->withBody(Str::limit($reason, 500), 'text/plain')->post($url.'/fail'), report: false);
    }

    private static function database(string $url): void
    {
        DB::select('select 1');
        self::success($url);
    }

    private static function cache(string $url): void
    {
        $key = 'serverscope:self-ping:cache';
        $value = Str::random(16);
        Cache::put($key, $value, 120);

        Cache::get($key) === $value ? self::success($url) : self::fail($url, 'The cache did not return the value it was given.');
    }
}
