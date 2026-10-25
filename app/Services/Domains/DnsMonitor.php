<?php

namespace App\Services\Domains;

use App\Events\DnsRecordsChanged;
use App\Models\DnsChange;
use App\Models\DnsSnapshot;
use App\Models\Domain;
use App\Monitoring\Dns\DnsException;
use App\Monitoring\Dns\Resolver;
use App\Monitoring\Dns\ResolverFactory;
use App\Services\Incidents\IncidentManager;
use App\Services\Incidents\Signal;
use Illuminate\Support\Facades\DB;

/**
 * DNS record monitoring.
 *
 * - Resolution failure (NXDOMAIN, or no A/AAAA answer possible because
 *   the queries fail) counts towards failure_threshold and opens a
 *   critical "dns" incident.
 * - Record sets are stored as snapshots; a differing set creates a
 *   DnsChange (with added/removed values). The first check is a baseline.
 * - Records of a type whose query failed are left untouched, so a
 *   timeout never looks like "all records removed".
 * - A/NS answers from each configured resolver are compared to report
 *   propagation inconsistencies.
 */
class DnsMonitor
{
    public const RECORD_TYPES = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS', 'CAA'];

    public function __construct(
        private Resolver $resolver,
        private ResolverFactory $resolvers,
        private IncidentManager $incidents,
    ) {}

    public static function fingerprint(Domain $domain): string
    {
        return "domain:{$domain->id}:dns";
    }

    public function check(Domain $domain): void
    {
        $started = microtime(true);
        $records = [];
        $errors = [];
        $nxdomain = false;

        foreach (self::RECORD_TYPES as $type) {
            try {
                $response = $this->resolver->query($domain->name, $type);

                if ($response->isNxDomain()) {
                    $nxdomain = true;
                    break;
                }

                $records[$type] = $response->values($type);
            } catch (DnsException $exception) {
                $errors[$type] = $exception->getMessage();
            }
        }

        $addressFailed = isset($errors['A']) && isset($errors['AAAA']);
        $success = ! $nxdomain && ! $addressFailed;
        $message = match (true) {
            $nxdomain => 'Het domein bestaat niet in DNS (NXDOMAIN).',
            $addressFailed => 'DNS-resolutie mislukt: '.$errors['A'],
            ($records['A'] ?? []) === [] && ($records['AAAA'] ?? []) === [] => 'Geen A- of AAAA-records gevonden.',
            default => null,
        };

        $propagation = $success ? $this->propagation($domain->name) : null;
        $durationMs = round((microtime(true) - $started) * 1000, 1);
        $now = now();
        $changes = [];

        DB::transaction(function () use ($domain, $records, $errors, $success, $message, $propagation, $durationMs, $now, &$changes): void {
            /** @var Domain $locked */
            $locked = Domain::query()->whereKey($domain->id)->lockForUpdate()->firstOrFail();

            if ($success) {
                foreach ($records as $type => $values) {
                    if ($change = $this->snapshot($locked, $type, $values, $now)) {
                        $changes[] = $change;
                    }
                }
            }

            $locked->checks()->create([
                'type' => 'dns',
                'result' => $success ? 'ok' : 'failed',
                'success' => $success,
                'response_time_ms' => $durationMs,
                'error_code' => $success ? null : 'resolution_failed',
                'message' => $message,
                'details' => array_filter([
                    'records' => $records ?: null,
                    'errors' => $errors ?: null,
                    'changed_types' => array_map(fn (DnsChange $c) => $c->record_type, $changes) ?: null,
                    'propagation' => $propagation,
                ]),
                'checked_at' => $now,
            ]);

            $locked->dns_last_checked_at = $now;
            $locked->dns_message = $message;

            if ($changes !== []) {
                $locked->dns_last_changed_at = $now;
            }

            if ($success) {
                $locked->dns_consecutive_failures = 0;
                $wasFailed = $locked->dns_status === 'failed';
                $locked->dns_status = 'ok';

                if ($wasFailed || $this->incidents->isActive(self::fingerprint($locked))) {
                    $this->incidents->recover(self::fingerprint($locked), "DNS-resolutie van {$locked->displayName()} werkt weer.");
                }
            } else {
                $locked->dns_consecutive_failures++;

                if ($locked->dns_consecutive_failures >= $locked->failure_threshold) {
                    $locked->dns_status = 'failed';

                    if (! $locked->isInMaintenance($now)) {
                        $this->incidents->trigger(new Signal(
                            fingerprint: self::fingerprint($locked),
                            source: 'dns',
                            severity: 'critical',
                            title: "DNS-resolutie mislukt voor {$locked->displayName()}",
                            description: $message,
                            subject: $locked,
                        ));
                    }
                }
            }

            $locked->save();
            DomainStatus::refresh($locked);
            $domain->setRawAttributes($locked->getAttributes(), true);
        });

        $important = array_values(array_filter(
            $changes,
            fn (DnsChange $c) => in_array($c->record_type, config('serverscope.monitoring.dns_alert_types', []), true),
        ));

        if ($important !== []) {
            DnsRecordsChanged::dispatch($domain, $important);
        }
    }

    private function snapshot(Domain $domain, string $type, array $values, $now): ?DnsChange
    {
        sort($values, SORT_STRING);
        $hash = hash('sha256', json_encode($values));

        /** @var DnsSnapshot|null $latest */
        $latest = $domain->dnsSnapshots()
            ->where('record_type', $type)
            ->latest('last_seen_at')
            ->latest('id')
            ->first();

        if ($latest !== null && $latest->hash === $hash) {
            $latest->forceFill(['last_seen_at' => $now])->save();

            return null;
        }

        $domain->dnsSnapshots()->create([
            'record_type' => $type,
            'records' => $values,
            'hash' => $hash,
            'first_seen_at' => $now,
            'last_seen_at' => $now,
        ]);

        // The first snapshot is the baseline, not a change.
        if ($latest === null) {
            return null;
        }

        $previous = $latest->records ?? [];

        return $domain->dnsChanges()->create([
            'record_type' => $type,
            'previous' => $previous,
            'current' => $values,
            'added' => array_values(array_diff($values, $previous)),
            'removed' => array_values(array_diff($previous, $values)),
            'detected_at' => $now,
        ]);
    }

    /**
     * @return array{consistent: bool, resolvers: array<string, array<string, mixed>>}|null
     */
    private function propagation(string $name): ?array
    {
        $nameservers = config('serverscope.monitoring.nameservers', []);

        if (count($nameservers) < 2) {
            return null;
        }

        $answers = [];

        foreach ($nameservers as $ip) {
            $resolver = $this->resolvers->forNameserver($ip);

            foreach (['A', 'NS'] as $type) {
                try {
                    $answers[$ip][$type] = $resolver->query($name, $type)->values($type);
                } catch (DnsException) {
                    $answers[$ip][$type] = null;
                }
            }
        }

        $sets = array_unique(array_map(fn ($a) => json_encode(array_filter($a, fn ($v) => $v !== null)), $answers));

        return ['consistent' => count($sets) === 1, 'resolvers' => $answers];
    }
}
