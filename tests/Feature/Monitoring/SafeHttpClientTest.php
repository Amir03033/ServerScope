<?php

namespace Tests\Feature\Monitoring;

use App\Monitoring\Dns\Resolver;
use App\Monitoring\Net\BlockedTargetException;
use App\Monitoring\Net\IpPolicy;
use App\Monitoring\Net\SafeHttpClient;
use App\Monitoring\Net\TargetGuard;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\FakeResolver;
use Tests\TestCase;

class SafeHttpClientTest extends TestCase
{
    private FakeResolver $dns;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dns = new FakeResolver;
        $this->app->instance(Resolver::class, $this->dns);
        $this->dns->host('example.com', '93.184.215.14');
        $this->dns->host('www.example.com', '93.184.215.14');
        Http::preventStrayRequests();
    }

    private function guard(): TargetGuard
    {
        return app(TargetGuard::class);
    }

    private function client(int $maxBytes = 1_048_576, int $perIp = 100): SafeHttpClient
    {
        return new SafeHttpClient($this->guard(), 5, $maxBytes, $perIp);
    }

    // ---- TargetGuard ----

    public function test_guard_rejects_unsafe_urls_without_any_dns_lookup(): void
    {
        $cases = [
            'ftp://example.com/' => 'scheme',
            'file:///etc/passwd' => 'invalid_url',
            'gopher://example.com/' => 'scheme',
            'http://user:pass@example.com/' => 'credentials',
            'http://example.com:22/' => 'port',
            'https://example.com:6379/' => 'port',
            'http://127.0.0.1/' => 'private_ip',
            'http://[::1]/' => 'private_ip',
            'http://[::ffff:169.254.169.254]/' => 'private_ip',
            'http://169.254.169.254/latest/meta-data/' => 'private_ip',
            'http://localhost/' => 'invalid_host',
            'http://metadata.google.internal/' => 'invalid_host',
            'http://printer.local/' => 'invalid_host',
            'http://intranet/' => 'invalid_host',
            'http://2130706433/' => 'invalid_host',
            'http://0x7f000001/' => 'invalid_host',
            'http://127.1/' => 'invalid_host',
        ];

        foreach ($cases as $url => $reason) {
            try {
                $this->guard()->resolve($url);
                $this->fail("{$url} should be blocked");
            } catch (BlockedTargetException $e) {
                $this->assertSame($reason, $e->reason, $url);
            }
        }

        $this->assertSame([], $this->dns->queries);
    }

    public function test_guard_rejects_hosts_resolving_to_private_addresses(): void
    {
        $this->dns->host('rebind.attacker.test', '10.0.0.5');
        $this->dns->host('meta.attacker.test', '169.254.169.254');
        $this->dns->set('v6.attacker.test', 'A', [])->set('v6.attacker.test', 'AAAA', ['::1']);
        // One public and one private answer: still rejected.
        $this->dns->host('mixed.attacker.test', '93.184.215.14', '127.0.0.1');

        foreach (['rebind', 'meta', 'v6', 'mixed'] as $host) {
            try {
                $this->guard()->resolve("http://{$host}.attacker.test/");
                $this->fail("{$host} should be blocked");
            } catch (BlockedTargetException $e) {
                $this->assertSame('private_ip', $e->reason);
            }
        }
    }

    public function test_guard_reports_dns_failures(): void
    {
        $this->dns->set('gone.example.org', 'A', 3)->set('gone.example.org', 'AAAA', 3);
        $this->dns->set('empty.example.org', 'A', [])->set('empty.example.org', 'AAAA', []);
        $this->dns->set('slow.example.org', 'A', FakeResolver::timeout())->set('slow.example.org', 'AAAA', FakeResolver::timeout());

        foreach (['gone' => 'nxdomain', 'empty' => 'no_address', 'slow' => 'dns_error'] as $host => $reason) {
            try {
                $this->guard()->resolve("https://{$host}.example.org/");
                $this->fail("{$host} should fail");
            } catch (BlockedTargetException $e) {
                $this->assertSame($reason, $e->reason);
            }
        }
    }

    public function test_guard_resolves_public_hosts_and_converts_idn(): void
    {
        $this->dns->host('xn--bcher-kva.example', '93.184.215.20');

        $target = $this->guard()->resolve('https://bücher.example/pad?q=1');

        $this->assertSame('xn--bcher-kva.example', $target->host);
        $this->assertSame('93.184.215.20', $target->ip);
        $this->assertSame(443, $target->port);
    }

    public function test_guard_prefers_ipv4_and_accepts_public_ipv6(): void
    {
        $this->dns->set('dual.example.net', 'A', ['93.184.215.30'])->set('dual.example.net', 'AAAA', ['2606:2800:21f::1']);
        $this->dns->set('v6only.example.net', 'A', [])->set('v6only.example.net', 'AAAA', ['2606:2800:21f::2']);

        $this->assertSame('93.184.215.30', $this->guard()->resolve('http://dual.example.net/')->ip);
        $this->assertSame('2606:2800:21f::2', $this->guard()->resolve('http://v6only.example.net/')->ip);
    }

    public function test_connections_are_pinned_to_the_vetted_ip_without_proxy(): void
    {
        $target = $this->guard()->resolve('https://example.com/');
        $options = $this->client()->connectionOptions($target, 5);

        $this->assertSame(['example.com:443:93.184.215.14'], $options['curl'][CURLOPT_RESOLVE]);
        $this->assertSame('', $options['proxy']);
        $this->assertFalse($options['allow_redirects']);
        $this->assertTrue($options['verify']);

        $this->dns->set('v6only.example.net', 'A', [])->set('v6only.example.net', 'AAAA', ['2606:2800:21f::2']);
        $v6 = $this->client()->connectionOptions($this->guard()->resolve('http://v6only.example.net/'), 5);
        $this->assertSame(['v6only.example.net:80:[2606:2800:21f::2]'], $v6['curl'][CURLOPT_RESOLVE]);
    }

    // ---- SafeHttpClient ----

    public function test_successful_request(): void
    {
        Http::fake(['https://example.com/*' => Http::response('<h1>Welkom</h1>', 200, ['Content-Type' => 'text/html'])]);

        $result = $this->client()->get('https://example.com/');

        $this->assertNull($result->error);
        $this->assertSame(200, $result->status);
        $this->assertSame('<h1>Welkom</h1>', $result->body);
        $this->assertSame('text/html', $result->headers['content-type']);
        $this->assertSame('93.184.215.14', $result->ip);
        $this->assertNotNull($result->durationMs);

        Http::assertSent(fn (Request $request) => str_starts_with($request->header('User-Agent')[0] ?? '', 'ServerScope-Monitor'));
    }

    public function test_redirects_are_followed_and_each_hop_is_validated(): void
    {
        Http::fake([
            'http://example.com/' => Http::response('', 301, ['Location' => 'https://www.example.com/start']),
            'https://www.example.com/start' => Http::response('', 302, ['Location' => '/home']),
            'https://www.example.com/home' => Http::response('ok', 200),
        ]);

        $result = $this->client()->get('http://example.com/');

        $this->assertNull($result->error);
        $this->assertSame('https://www.example.com/home', $result->finalUrl);
        $this->assertSame([301, 302], array_column($result->redirects, 'status'));
    }

    public function test_malicious_redirect_to_internal_address_is_blocked(): void
    {
        $this->dns->host('internal.attacker.test', '192.168.1.10');

        foreach ([
            'http://169.254.169.254/latest/meta-data/iam/',
            'http://127.0.0.1:80/admin',
            'http://internal.attacker.test/',
            'http://[::ffff:7f00:1]/',
            'gopher://example.com/',
            'http://example.com:25/',
        ] as $location) {
            Http::fake(['https://example.com/*' => Http::response('', 302, ['Location' => $location])]);

            $result = $this->client()->get('https://example.com/');

            $this->assertSame('blocked', $result->error, $location);
            $this->assertNull($result->status);
        }

        Http::assertNotSent(fn (Request $r) => ! str_starts_with($r->url(), 'https://example.com'));
    }

    public function test_redirect_loops_and_limits(): void
    {
        Http::fake([
            'https://example.com/a' => Http::response('', 302, ['Location' => '/b']),
            'https://example.com/b' => Http::response('', 302, ['Location' => '/a']),
        ]);

        $this->assertSame('redirect_loop', $this->client()->get('https://example.com/a')->error);

        Http::fake(fn (Request $r) => Http::response('', 302, ['Location' => $r->url().'x']));
        $this->assertSame('too_many_redirects', $this->client()->get('https://example.com/')->error);
    }

    public function test_redirects_are_not_followed_when_disabled(): void
    {
        Http::fake(['*' => Http::response('', 301, ['Location' => 'https://example.com/elders'])]);

        $result = $this->client()->get('https://example.com/', followRedirects: false);

        $this->assertSame(301, $result->status);
        $this->assertSame([], $result->redirects);
    }

    public function test_response_body_is_capped(): void
    {
        Http::fake(['*' => Http::response(str_repeat('x', 5000), 200)]);

        $result = $this->client(maxBytes: 1000)->get('https://example.com/');

        $this->assertSame(200, $result->status);
        $this->assertSame(1000, strlen($result->body));
        $this->assertTrue($result->truncated);
    }

    public function test_network_errors_are_classified(): void
    {
        $cases = [
            'cURL error 28: Operation timed out after 10001 milliseconds' => 'timeout',
            'cURL error 7: Failed to connect to example.com port 443: Connection refused' => 'connection_failed',
            'cURL error 60: SSL certificate problem: certificate has expired' => 'tls_error',
        ];

        foreach ($cases as $message => $expected) {
            Http::swap(new \Illuminate\Http\Client\Factory);
            Http::fake(fn () => throw new ConnectionException($message));

            $result = $this->client()->get('https://example.com/');

            $this->assertSame($expected, $result->error, $message);
            $this->assertNotEmpty($result->errorMessage);
        }
    }

    public function test_requests_per_target_ip_are_rate_limited(): void
    {
        RateLimiter::clear('monitor-ip:93.184.215.14');
        Http::fake(['*' => Http::response('ok')]);
        $client = $this->client(perIp: 2);

        $this->assertNull($client->get('https://example.com/')->error);
        $this->assertNull($client->get('https://www.example.com/')->error);
        $this->assertSame('rate_limited', $client->get('https://example.com/')->error);

        Http::assertSentCount(2);
    }

    public function test_relative_location_resolution(): void
    {
        $cases = [
            ['https://a.example/x/y', '/z', 'https://a.example/z'],
            ['https://a.example/x/y', 'z', 'https://a.example/x/z'],
            ['https://a.example/x/y', '../z', 'https://a.example/z'],
            ['https://a.example:8443/x', '?q=1', 'https://a.example:8443/x?q=1'],
            ['https://a.example/x', '//b.example/p', 'https://b.example/p'],
            ['https://a.example/x', 'http://c.example/', 'http://c.example/'],
        ];

        foreach ($cases as [$base, $location, $expected]) {
            $this->assertSame($expected, SafeHttpClient::resolveUrl($base, $location));
        }

        $this->assertNull(SafeHttpClient::resolveUrl('https://a.example/', "/x\r\nSet-Cookie: a=b"));
    }

    public function test_private_targets_can_be_allowed_explicitly_for_development(): void
    {
        $this->app->instance(IpPolicy::class, new IpPolicy(false, ['10.0.0.0/8']));
        $this->dns->host('staging.lan.example', '10.1.2.3');

        $this->assertSame('10.1.2.3', $this->guard()->resolve('http://staging.lan.example/')->ip);
    }
}
