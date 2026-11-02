<?php

namespace App\Services\Intelligence;

use Carbon\CarbonImmutable;

/**
 * The evidence snapshot of one investigation, as rules see it: the
 * incident (codes only), the window, the resources in scope with their
 * dependency paths, the evidence items, the data gaps and the status of
 * every collector. Plain data, no database access.
 */
final class InvestigationContext
{
    /**
     * @param  array<string, mixed>  $incident  id, source, severity, status, subject, failure_kind, parent_id, cause_incident_id
     * @param  array<string, CarbonImmutable|bool|null>  $window
     * @param  array<string, array<string, mixed>>  $resources  key => {key, type, id, label, relation, depth, path}
     * @param  array<string, array<string, mixed>>  $evidence  key => item
     * @param  array<int, array{code: string, params: array<string, mixed>}>  $gaps
     * @param  array<string, string>  $collectors  name => ok|failed
     * @param  array<int, string>  $truncated
     */
    public function __construct(
        public readonly array $incident,
        public readonly array $window,
        public readonly array $resources,
        public readonly array $evidence,
        public readonly array $gaps,
        public readonly array $collectors,
        public readonly array $truncated,
    ) {}

    public function onset(): CarbonImmutable
    {
        return $this->window['onset'];
    }

    /** The end of the incident, or the end of the window while it is still active. */
    public function end(): CarbonImmutable
    {
        return $this->window['end'] ?? $this->window['to'];
    }

    public function subject(): ?string
    {
        return $this->incident['subject'];
    }

    public function failureKind(): ?string
    {
        return $this->incident['failure_kind'];
    }

    public function relation(?string $key): ?string
    {
        return $key !== null ? ($this->resources[$key]['relation'] ?? null) : null;
    }

    /** Whether the resource is the incident's own or something it depends on. */
    public function isUpstreamOrSubject(?string $key): bool
    {
        return in_array($this->relation($key), ['subject', 'upstream', 'service'], true);
    }

    /** @return array<int, string> */
    public function path(?string $key): array
    {
        return $key !== null ? ($this->resources[$key]['path'] ?? [$key]) : [];
    }

    public static function at(array $item): ?CarbonImmutable
    {
        return isset($item['at']) ? CarbonImmutable::parse($item['at']) : null;
    }

    /**
     * Evidence items with one of these codes, optionally filtered.
     *
     * @param  array<int, string>|string  $codes
     * @return array<string, array<string, mixed>>
     */
    public function items(array|string $codes, ?callable $filter = null): array
    {
        $codes = (array) $codes;

        return array_filter($this->evidence, fn (array $item) => in_array($item['code'], $codes, true) && ($filter === null || $filter($item)));
    }

    /** The check summary item of a resource (counts per phase), or null. */
    public function checks(string $key, string $code = 'check_summary'): ?array
    {
        foreach ($this->evidence as $item) {
            if ($item['code'] === $code && $item['resource'] === $key) {
                return $item;
            }
        }

        return null;
    }

    /** Whether metric history of this server is missing for the window. */
    public function metricGap(?string $serverKey): bool
    {
        foreach ($this->gaps as $gap) {
            if (in_array($gap['code'], ['no_metrics', 'no_raw'], true) && ($gap['params']['resource'] ?? null) === $serverKey) {
                return true;
            }
        }

        return false;
    }

    /** @param  array<int, string>  $sources */
    public function failed(array $sources): bool
    {
        foreach ($sources as $source) {
            if (($this->collectors[$source] ?? 'ok') === 'failed') {
                return true;
            }
        }

        return false;
    }

    /** @param  array<int, string>  $sources */
    public function wasTruncated(array $sources): bool
    {
        return array_intersect($sources, $this->truncated) !== [];
    }
}
