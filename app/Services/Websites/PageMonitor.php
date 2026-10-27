<?php

namespace App\Services\Websites;

use App\Models\MonitoredPage;
use App\Models\PageChange;
use App\Monitoring\Net\HttpResult;
use App\Monitoring\Net\SafeHttpClient;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Checks one monitored page.
 *
 * Every request goes through SafeHttpClient (SSRF rules per URL and per
 * redirect, IP pinning). Failures are classified so a network problem,
 * a TLS error, an unexpected status code and a failed content assertion
 * are never confused:
 *
 *   network | timeout | dns | tls | blocked | redirect   (no usable answer)
 *   status | content | header                            (answer, but wrong)
 *   slow                                                 (degraded, not a failure)
 *
 * A failure only marks the page "down" after failure_threshold checks in
 * a row; it recovers after recovery_threshold successes. Content changes
 * are recorded, never treated as failures.
 */
class PageMonitor
{
    public function __construct(
        private SafeHttpClient $client,
        private PageIncidentService $incidents,
    ) {}

    /**
     * @return array{skipped: bool, success?: bool, failure_type?: ?string}
     */
    public function check(MonitoredPage $page): array
    {
        $result = $this->client->get($page->url, (float) $page->timeout_seconds, $page->follow_redirects);
        $now = now();

        // Our own per-IP rate limit is not a failure of the website.
        if ($result->error === 'rate_limited') {
            MonitoredPage::query()->whereKey($page->id)->update(['next_check_at' => $now->copy()->addMinute()]);

            return ['skipped' => true];
        }

        [$failureType, $message] = $this->evaluate($page, $result);
        $slow = $failureType === null && $page->max_response_ms !== null && $result->durationMs !== null && $result->durationMs > $page->max_response_ms;

        $change = null;

        DB::transaction(function () use ($page, $result, $failureType, $message, $slow, $now, &$change): void {
            /** @var MonitoredPage $locked */
            $locked = MonitoredPage::query()->whereKey($page->id)->lockForUpdate()->firstOrFail();
            $locked->setRelation('domain', $page->domain);

            $success = $failureType === null;
            $contentChanged = false;

            if ($success) {
                $change = $this->detectChanges($locked, $result, $now);
                $contentChanged = $change !== null && $change->kind === 'content';
            }

            $locked->checks()->create([
                'domain_id' => $locked->domain_id,
                'result' => $success ? ($slow ? 'degraded' : 'up') : 'down',
                'success' => $success,
                'failure_type' => $success ? ($slow ? 'slow' : null) : $failureType,
                'http_status_code' => $result->status,
                'response_time_ms' => $result->durationMs,
                'error_code' => $result->error ?? ($success ? null : $failureType),
                'message' => $message !== null ? mb_substr($message, 0, 500) : ($slow ? "Responstijd {$result->durationMs} ms boven de drempel van {$locked->max_response_ms} ms." : null),
                'content_changed' => $contentChanged,
                'details' => array_filter([
                    'final_url' => $result->finalUrl !== $result->url ? $result->finalUrl : null,
                    'redirects' => $result->redirects ?: null,
                    'ip' => $result->ip,
                    'truncated' => $result->truncated ?: null,
                ]),
                'checked_at' => $now,
            ]);

            $locked->forceFill([
                'last_checked_at' => $now,
                'last_status_code' => $result->status,
                'last_response_ms' => $result->durationMs,
                'last_final_url' => $result->finalUrl ? mb_substr($result->finalUrl, 0, 2048) : null,
                'last_error' => $message !== null ? mb_substr($message, 0, 500) : null,
                'next_check_at' => $now->copy()->addMinutes(max(1, $locked->check_interval_minutes)),
            ]);

            if ($locked->isHttps() && ($result->status !== null || $failureType === 'tls')) {
                $locked->tls_valid = $failureType !== 'tls';
            }

            if ($title = $this->title($result)) {
                $locked->title = $title;
            }

            if ($success) {
                $locked->last_success_at = $now;
                $locked->consecutive_failures = 0;
                $locked->consecutive_successes++;
                $locked->last_failure_type = $slow ? 'slow' : null;

                $recovering = $locked->status === 'down';

                if (! $recovering || $locked->consecutive_successes >= $locked->recovery_threshold) {
                    $locked->status = $slow ? 'degraded' : 'up';
                    $locked->failing_since = null;

                    if ($recovering) {
                        $this->incidents->recovered($locked, "{$locked->url} is weer bereikbaar (HTTP {$result->status}, ".round((float) $result->durationMs).' ms).');
                    }
                }
            } else {
                $locked->consecutive_successes = 0;
                $locked->consecutive_failures++;
                $locked->last_failure_at = $now;
                $locked->last_failure_type = $failureType;
                $locked->failing_since ??= $now;

                if ($locked->consecutive_failures >= $locked->failure_threshold) {
                    $locked->status = 'down';

                    if (! $locked->domain?->isInMaintenance($now)) {
                        $this->incidents->failed($locked, $failureType, (string) $message, $result->status);
                    }
                } elseif ($locked->status !== 'down') {
                    $locked->status = 'degraded';
                }
            }

            $locked->save();
            $page->setRawAttributes($locked->getAttributes(), true);
        });

        if ($change !== null && $change->significance === 'significant' && $page->notify_content_changes && $change->kind === 'content') {
            app(NotificationDispatcher::class)->pageChange($page, $change);
        }

        return ['skipped' => false, 'success' => $failureType === null, 'failure_type' => $failureType ?? ($slow ? 'slow' : null)];
    }

    /**
     * @return array{0: ?string, 1: ?string} failure type and message (null when OK)
     */
    public function evaluate(MonitoredPage $page, HttpResult $result): array
    {
        if ($result->failed()) {
            $type = match ($result->error) {
                'timeout' => 'timeout',
                'dns_error' => 'dns',
                'tls_error' => 'tls',
                'blocked' => 'blocked',
                'redirect_loop', 'too_many_redirects', 'invalid_redirect' => 'redirect',
                default => 'network',
            };

            return [$type, $result->errorMessage ?? 'Verzoek mislukt.'];
        }

        if (! $page->acceptsStatus((int) $result->status)) {
            return ['status', "Onverwachte HTTP-statuscode {$result->status}".($page->expected_status_codes ? " (verwacht: {$page->expected_status_codes})." : '.')];
        }

        if ($page->expected_final_url !== null && $page->expected_final_url !== '') {
            $expected = UrlNormalizer::normalize($page->expected_final_url, keepQuery: true);
            $actual = UrlNormalizer::normalize((string) $result->finalUrl, keepQuery: true);

            if ($expected !== $actual) {
                return ['redirect', "Eindigde op {$result->finalUrl} in plaats van {$page->expected_final_url}."];
            }
        }

        $truncated = $result->truncated ? ' (antwoord ingekort tot de maximale grootte)' : '';

        if ($page->keyword_present !== null && $page->keyword_present !== '' && ! str_contains($result->body, $page->keyword_present)) {
            return ['content', "De verwachte tekst \"{$page->keyword_present}\" is niet gevonden{$truncated}."];
        }

        if ($page->keyword_absent !== null && $page->keyword_absent !== '' && str_contains($result->body, $page->keyword_absent)) {
            return ['content', "De tekst \"{$page->keyword_absent}\" staat op de pagina maar mag er niet staan."];
        }

        foreach ((array) $page->header_assertions as $assertion) {
            $failure = $this->headerFailure($assertion, $result->headers);

            if ($failure !== null) {
                return ['header', $failure];
            }
        }

        return [null, null];
    }

    /** @param  array<string, string>  $headers  lower-case names */
    private function headerFailure(mixed $assertion, array $headers): ?string
    {
        if (! is_array($assertion) || ! isset($assertion['name']) || ! is_string($assertion['name'])) {
            return null;
        }

        $name = strtolower(trim($assertion['name']));
        $mode = $assertion['mode'] ?? 'present';
        $expected = (string) ($assertion['value'] ?? '');
        $actual = $headers[$name] ?? null;

        return match ($mode) {
            'absent' => $actual !== null ? "Header {$name} is aanwezig maar zou moeten ontbreken." : null,
            'equals' => $actual === null ? "Header {$name} ontbreekt." : (trim($actual) !== $expected ? "Header {$name} is \"".mb_substr($actual, 0, 100)."\" in plaats van \"{$expected}\"." : null),
            'contains' => $actual === null ? "Header {$name} ontbreekt." : (! str_contains(strtolower($actual), strtolower($expected)) ? "Header {$name} bevat \"{$expected}\" niet." : null),
            default => $actual === null ? "Header {$name} ontbreekt." : null,
        };
    }

    /** Content and monitored-header changes; returns the most relevant change. */
    private function detectChanges(MonitoredPage $page, HttpResult $result, Carbon $now): ?PageChange
    {
        $change = null;

        $monitored = array_values(array_filter(array_map(fn ($h) => is_string($h) ? strtolower(trim($h)) : null, (array) $page->monitored_headers)));

        if ($monitored !== []) {
            $snapshot = [];

            foreach ($monitored as $name) {
                $snapshot[$name] = isset($result->headers[$name]) ? mb_substr($result->headers[$name], 0, 500) : null;
            }

            $previous = $page->header_snapshot;
            $page->header_snapshot = $snapshot;

            if (is_array($previous) && $previous != $snapshot) {
                $changed = [];

                foreach ($snapshot as $name => $value) {
                    if (($previous[$name] ?? null) !== $value) {
                        $changed[$name] = ['from' => $previous[$name] ?? null, 'to' => $value];
                    }
                }

                $change = $page->changes()->create([
                    'domain_id' => $page->domain_id,
                    'kind' => 'headers',
                    'previous_fingerprint' => hash('sha256', json_encode($previous)),
                    'new_fingerprint' => hash('sha256', json_encode($snapshot)),
                    'significance' => 'significant',
                    'change_ratio' => null,
                    'summary' => 'Gewijzigde headers: '.implode(', ', array_keys($changed)),
                    'diff' => ['headers' => $changed],
                    'detected_at' => $now,
                ]);
            }
        }

        if (! $page->content_monitoring || $result->status === null || $result->status >= 300) {
            return $change;
        }

        $extracted = ContentFingerprint::extract($result->body, $result->headers['content-type'] ?? null, $page->content_selector, (array) $page->content_exclusions);
        $previousText = (string) $page->content_snapshot;
        $previousFingerprint = $page->content_fingerprint;

        $page->content_fingerprint = $extracted['fingerprint'];
        $page->content_snapshot = $extracted['text'];

        if ($previousFingerprint === null || $previousFingerprint === $extracted['fingerprint']) {
            return $change;
        }

        $diff = ContentFingerprint::diff($previousText, $extracted['text']);
        $significant = $diff['ratio'] >= (float) config('serverscope.websites.significant_change_ratio', 0.10);
        $page->content_changed_at = $now;

        return $page->changes()->create([
            'domain_id' => $page->domain_id,
            'kind' => 'content',
            'previous_fingerprint' => $previousFingerprint,
            'new_fingerprint' => $extracted['fingerprint'],
            'significance' => $significant ? 'significant' : 'minor',
            'change_ratio' => $diff['ratio'],
            'summary' => count($diff['added']).' regel(s) toegevoegd, '.count($diff['removed']).' verwijderd ('.round($diff['ratio'] * 100, 1).'% van de tekst)'.($extracted['selector_found'] ? '' : '; selector niet gevonden, hele pagina vergeleken'),
            'diff' => ['added' => $diff['added'], 'removed' => $diff['removed']],
            'detected_at' => $now,
        ]);
    }

    private function title(HttpResult $result): ?string
    {
        if ($result->status === null || $result->status >= 300 || ! preg_match('/<title[^>]*>(.*?)<\/title>/is', $result->body, $m)) {
            return null;
        }

        $title = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');

        return $title !== '' ? mb_substr($title, 0, 255) : null;
    }
}
