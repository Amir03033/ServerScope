<?php

namespace App\Services\Domains;

use App\Monitoring\Net\SafeHttpClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Registration data via RDAP (RFC 9082/9083) with IANA bootstrap
 * discovery (RFC 9224). Requests go through SafeHttpClient.
 *
 * Expiry information is only reported when the registry publishes an
 * "expiration" event; nothing is inferred or estimated.
 */
class RdapClient
{
    public function __construct(
        private SafeHttpClient $http,
        private string $bootstrapUrl = 'https://data.iana.org/rdap/dns.json',
    ) {}

    public function lookup(string $domain): RdapResult
    {
        $labels = explode('.', $domain);
        $services = $this->servicesFor($labels);

        if ($services === null) {
            return new RdapResult('error', 'De RDAP-registry (IANA bootstrap) kon niet worden opgehaald.');
        }

        [$suffix, $baseUrls] = $services;

        if ($baseUrls === []) {
            return new RdapResult('unsupported', 'Voor .'.$suffix.' is geen RDAP-dienst bekend bij IANA; de vervaldatum kan niet automatisch worden opgevraagd.');
        }

        // Try the name itself, then strip leftmost labels until only one
        // label is left before the registry suffix (sub.example.co.uk ->
        // example.co.uk).
        $suffixLabels = substr_count($suffix, '.') + 1;
        $candidates = [];

        for ($i = 0; count($labels) - $i > $suffixLabels; $i++) {
            $candidates[] = implode('.', array_slice($labels, $i));
        }

        $base = rtrim($baseUrls[0], '/').'/';

        foreach ($candidates as $candidate) {
            $url = $base.'domain/'.$candidate;
            $response = $this->http->get($url, 15.0, true, ['Accept' => 'application/rdap+json, application/json']);

            if ($response->failed()) {
                return new RdapResult('error', 'RDAP-verzoek mislukt: '.$response->errorMessage, source: $url);
            }

            if ($response->status === 404) {
                continue;
            }

            if ($response->status === 429) {
                $retry = (int) ($response->headers['retry-after'] ?? 0);

                return new RdapResult('rate_limited', 'De registry beperkt het aantal verzoeken (HTTP 429).', source: $url, retryAfterSeconds: $retry > 0 ? min($retry, 86400) : null);
            }

            if ($response->status !== 200) {
                return new RdapResult('error', "De registry antwoordde met HTTP {$response->status}.", source: $url);
            }

            return $this->parse($response->body, $response->finalUrl ?? $url, $candidate);
        }

        return new RdapResult('not_found', 'De registry kent dit domein niet (RDAP 404). Controleer de spelling of de registratie.', source: $base.'domain/'.end($candidates));
    }

    public function parse(string $body, string $source, string $domain): RdapResult
    {
        try {
            $data = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return new RdapResult('error', 'Ongeldig RDAP-antwoord (geen JSON).', source: $source);
        }

        if (! is_array($data) || ($data['objectClassName'] ?? null) !== 'domain') {
            return new RdapResult('error', 'Onverwacht RDAP-antwoord.', source: $source);
        }

        $expires = null;
        $registered = null;

        foreach ((array) ($data['events'] ?? []) as $event) {
            $date = $this->date($event['eventDate'] ?? null);

            match ($event['eventAction'] ?? null) {
                'expiration' => $expires = $date,
                'registration' => $registered = $date,
                default => null,
            };
        }

        $registrar = null;

        foreach ((array) ($data['entities'] ?? []) as $entity) {
            if (in_array('registrar', (array) ($entity['roles'] ?? []), true)) {
                $registrar = $this->vcardName($entity) ?? ($entity['handle'] ?? null);
                break;
            }
        }

        $statuses = array_values(array_filter((array) ($data['status'] ?? []), 'is_string'));
        $registrable = strtolower((string) ($data['ldhName'] ?? $domain));

        if ($expires === null) {
            return new RdapResult(
                'unavailable',
                'De registry publiceert geen vervaldatum via RDAP. Controleer de vervaldatum bij je registrar.',
                registeredAt: $registered,
                registrar: $registrar,
                source: $source,
                registrableDomain: $registrable,
                statuses: $statuses,
            );
        }

        return new RdapResult('verified', null, $expires, $registered, $registrar, $source, $registrable, $statuses);
    }

    /**
     * Find the longest registry suffix in the bootstrap data.
     *
     * @return array{0: string, 1: array<int, string>}|null
     */
    private function servicesFor(array $labels): ?array
    {
        $map = $this->bootstrap();

        if ($map === null) {
            return null;
        }

        for ($i = 1; $i < count($labels); $i++) {
            $suffix = implode('.', array_slice($labels, $i));

            if (isset($map[$suffix])) {
                return [$suffix, $map[$suffix]];
            }
        }

        return [end($labels), []];
    }

    /**
     * @return array<string, array<int, string>>|null suffix => https base URLs
     */
    private function bootstrap(): ?array
    {
        $cached = Cache::get('rdap:bootstrap');

        if (is_array($cached)) {
            return $cached;
        }

        $response = $this->http->get($this->bootstrapUrl, 15.0, true, ['Accept' => 'application/json']);

        if ($response->failed() || $response->status !== 200) {
            return null;
        }

        $data = json_decode($response->body, true);

        if (! is_array($data) || ! isset($data['services']) || ! is_array($data['services'])) {
            return null;
        }

        $map = [];

        foreach ($data['services'] as $service) {
            [$suffixes, $urls] = [(array) ($service[0] ?? []), (array) ($service[1] ?? [])];
            $https = array_values(array_filter($urls, fn ($u) => is_string($u) && str_starts_with($u, 'https://')));

            foreach ($suffixes as $suffix) {
                if (is_string($suffix)) {
                    $map[strtolower($suffix)] = $https;
                }
            }
        }

        Cache::put('rdap:bootstrap', $map, now()->addDay());

        return $map;
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (Throwable) {
            return null;
        }
    }

    private function vcardName(array $entity): ?string
    {
        foreach ((array) ($entity['vcardArray'][1] ?? []) as $property) {
            if (is_array($property) && ($property[0] ?? null) === 'fn' && is_string($property[3] ?? null) && $property[3] !== '') {
                return mb_substr($property[3], 0, 255);
            }
        }

        return null;
    }
}
