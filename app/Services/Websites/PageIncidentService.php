<?php

namespace App\Services\Websites;

use App\Models\Domain;
use App\Models\Incident;
use App\Models\IncidentImpact;
use App\Models\MonitoredPage;
use App\Services\Incidents\IncidentManager;
use App\Services\Incidents\Signal;
use App\Support\Severity;

/**
 * Turns page failures into incidents without one incident per page for
 * a shared cause.
 *
 * - Failures that point to something outside the page itself (network,
 *   time-out, DNS, TLS, blocked target, HTTP 5xx) are grouped per website
 *   host and failure type once `group_min_pages` pages fail the same way:
 *   one website incident lists every affected page (incident_impacts).
 * - Page-specific failures (a 404, a missing keyword, a header or
 *   redirect assertion) get an incident for that page only; the other
 *   pages keep their own status.
 * - A grouped incident resolves when its last affected page recovered.
 * The correlation engine can then group either kind under a server,
 * service or certificate incident via confirmed dependencies.
 */
class PageIncidentService
{
    private const SHARED_TYPES = ['network', 'timeout', 'dns', 'tls', 'blocked'];

    public const GROUP_LABELS = [
        'network' => 'netwerkfout',
        'timeout' => 'time-out',
        'dns' => 'DNS-fout',
        'tls' => 'TLS-fout',
        'blocked' => 'geblokkeerd doel',
        'server_error' => 'serverfout (5xx)',
    ];

    public function __construct(
        private IncidentManager $incidents,
    ) {}

    /** The shared-cause group of a failure, or null when page-specific. */
    public static function groupKey(?string $failureType, ?int $statusCode): ?string
    {
        if (in_array($failureType, self::SHARED_TYPES, true)) {
            return $failureType;
        }

        return $failureType === 'status' && $statusCode !== null && $statusCode >= 500 ? 'server_error' : null;
    }

    public static function pageFingerprint(MonitoredPage $page): string
    {
        return "page:{$page->id}";
    }

    public static function groupFingerprint(Domain $domain, string $host, string $group): string
    {
        return "website:{$domain->id}:{$host}:{$group}";
    }

    public function failed(MonitoredPage $page, string $failureType, string $message, ?int $statusCode): void
    {
        $domain = $page->domain;
        $group = self::groupKey($failureType, $statusCode);
        $context = ['failure_type' => $failureType, 'status_code' => $statusCode, 'page_id' => $page->id, 'url' => $page->url];

        $fingerprint = $group !== null ? self::groupFingerprint($domain, $page->host(), $group) : null;

        // The page fails for another reason now: it no longer belongs to
        // a website incident for a different cause.
        $this->release($page, "Pagina {$page->url} faalt nu om een andere reden ({$failureType}).", $fingerprint);

        if ($group !== null) {
            $peers = $this->failingPeers($page, $group);

            if ($peers->count() + 1 >= max(1, (int) config('serverscope.websites.group_min_pages', 2)) || $this->incidents->isActive($fingerprint)) {
                $this->failGroup($page, $peers, $fingerprint, $group, $failureType, $message, $statusCode);

                return;
            }
        }

        $incident = $this->incidents->trigger(new Signal(
            fingerprint: self::pageFingerprint($page),
            source: 'page',
            severity: Severity::isValid($page->alert_severity) ? $page->alert_severity : Severity::HIGH,
            title: "Pagina {$page->displayName()} van {$domain->displayName()}: ".mb_strtolower(MonitoredPage::FAILURE_LABELS[$failureType] ?? $failureType),
            description: "{$message} ({$page->consecutive_failures} opeenvolgende mislukte controles van {$page->url}).",
            subject: $page,
            context: $context,
            escalationKey: $failureType,
            startedAt: $page->failing_since,
        ));

        $this->markImpact($incident, $page, $failureType, $message);
    }

    public function recovered(MonitoredPage $page, string $message): void
    {
        $this->incidents->recover(self::pageFingerprint($page), $message);
        $this->release($page, "Pagina {$page->url} is hersteld.", recovering: true);
    }

    /**
     * Mark the page as no longer failing in website incidents (except the
     * one with $keepFingerprint) and resolve those that have no failing
     * page left.
     */
    private function release(MonitoredPage $page, string $message, ?string $keepFingerprint = null, bool $recovering = false): void
    {
        $impacts = IncidentImpact::query()
            ->where('resource_type', 'page')
            ->where('resource_id', $page->id)
            ->where('status', 'failing')
            ->whereHas('incident', fn ($q) => $q->active())
            ->with('incident')
            ->get()
            ->reject(fn (IncidentImpact $impact) => $keepFingerprint !== null && $impact->incident->fingerprint === $keepFingerprint);

        foreach ($impacts as $impact) {
            $incident = $impact->incident;

            if (! str_starts_with($incident->fingerprint, 'website:')) {
                // The page's own incident: its recovery is handled above.
                if ($recovering) {
                    $impact->forceFill(['status' => 'recovered', 'recovered_at' => now()])->save();
                }

                continue;
            }

            $impact->forceFill(['status' => 'recovered', 'recovered_at' => now()])->save();
            $this->incidents->event($incident, 'resource_recovered', null, $message, ['page_id' => $page->id]);

            $stillFailing = $incident->impacts()->where('resource_type', 'page')->where('status', 'failing')->count();

            if ($stillFailing === 0) {
                $this->incidents->recover($incident->fingerprint, "Alle getroffen pagina's van {$page->domain->displayName()} zijn weer bereikbaar.");
            }
        }
    }

    /** Pages of the same website and host that are down for the same group. */
    private function failingPeers(MonitoredPage $page, string $group)
    {
        return MonitoredPage::query()
            ->where('domain_id', $page->domain_id)
            ->whereKeyNot($page->id)
            ->monitored()
            ->where('status', 'down')
            ->get()
            ->filter(fn (MonitoredPage $peer) => $peer->host() === $page->host() && self::groupKey($peer->last_failure_type, $peer->last_status_code) === $group)
            ->values();
    }

    private function failGroup(MonitoredPage $page, $peers, string $fingerprint, string $group, string $failureType, string $message, ?int $statusCode): void
    {
        $domain = $page->domain;
        $affected = $peers->push($page);
        $severity = $affected->reduce(fn (string $carry, MonitoredPage $p) => Severity::max($carry, Severity::isValid($p->alert_severity) ? $p->alert_severity : Severity::HIGH), Severity::LOW);
        $count = $affected->count();
        $label = self::GROUP_LABELS[$group] ?? $group;

        $incident = $this->incidents->trigger(new Signal(
            fingerprint: $fingerprint,
            source: 'page',
            severity: $severity,
            title: "{$count} ".($count === 1 ? 'pagina' : "pagina's")." van {$domain->displayName()} falen ({$label})",
            description: "Meerdere pagina's op {$page->host()} falen met dezelfde oorzaak ({$label}). Laatste melding: {$message}",
            subject: $domain,
            context: ['failure_type' => $failureType, 'group' => $group, 'host' => $page->host(), 'status_code' => $statusCode],
            escalationKey: "pages:{$count}",
            startedAt: $affected->min('failing_since'),
        ));

        foreach ($affected as $member) {
            $created = $this->markImpact($incident, $member, $member->is($page) ? $failureType : ($member->last_failure_type ?? $failureType), $member->is($page) ? $message : ($member->last_error ?? $message));

            if ($created) {
                $this->incidents->event($incident, 'resource_affected', null, "Pagina {$member->url} faalt: ".($member->is($page) ? $message : ($member->last_error ?? $label)), ['page_id' => $member->id]);
            }

            // A page that already had its own incident for this problem
            // moves under the website incident instead of staying separate.
            $own = Incident::query()->where('active_fingerprint', self::pageFingerprint($member))->first();

            if ($own !== null && $own->parent_id === null && $own->id !== $incident->id) {
                $own->forceFill(['parent_id' => $incident->id, 'correlation' => [
                    'reason_code' => 'shared_cause',
                    'reason' => "Meerdere pagina's van {$domain->displayName()} falen met dezelfde oorzaak ({$label}).",
                    'path' => ["page:{$member->id}", "domain:{$domain->id}"],
                    'cause_incident_id' => $incident->id,
                    'failure_kind' => $group,
                    'correlated_at' => now()->toIso8601String(),
                ]])->save();
                $this->incidents->event($own, 'correlated', null, "Gegroepeerd onder incident #{$incident->id}: meerdere pagina's falen met dezelfde oorzaak.", ['parent_id' => $incident->id]);
            }
        }
    }

    /** Record (or refresh) a page as affected by an incident; true when new. */
    private function markImpact(Incident $incident, MonitoredPage $page, string $failureType, string $message): bool
    {
        $impact = IncidentImpact::query()->firstOrNew([
            'incident_id' => $incident->id, 'resource_type' => 'page', 'resource_id' => $page->id,
        ]);

        $isNew = ! $impact->exists || $impact->status !== 'failing';

        $impact->fill([
            'role' => 'affected',
            'status' => 'failing',
            'failure_type' => $failureType,
            'message' => mb_substr($message, 0, 500),
            'first_failed_at' => $isNew ? ($page->failing_since ?? now()) : $impact->first_failed_at,
            'last_failed_at' => now(),
            'recovered_at' => null,
            'details' => ['url' => $page->url, 'status_code' => $page->last_status_code],
        ])->save();

        return $isNew;
    }
}
