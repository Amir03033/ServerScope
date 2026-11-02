<?php

namespace App\Services\Incidents\Evidence\Collectors;

use App\Models\DnsChange;
use App\Services\Incidents\Evidence\Collector;
use App\Services\Incidents\Evidence\EvidenceRecord;
use App\Services\Incidents\Evidence\EvidenceScope;
use Carbon\CarbonImmutable;

/** Changed DNS records of the domains in scope. */
final class DnsChangeCollector implements Collector
{
    private const MAX_ROWS = 200;

    public function name(): string
    {
        return 'dns_changes';
    }

    public function collect(EvidenceScope $scope): array
    {
        if (! isset($scope->byType['domain'])) {
            return [];
        }

        $changes = DnsChange::query()->whereIn('domain_id', array_keys($scope->byType['domain']))
            ->whereBetween('detected_at', [$scope->window['from'], $scope->window['to']])->limit(self::MAX_ROWS)->get();

        if ($changes->count() >= self::MAX_ROWS) {
            $scope->truncate($this->name());
        }

        return $changes->map(fn (DnsChange $change) => new EvidenceRecord(CarbonImmutable::instance($change->detected_at), 'change', 'dns_changed', ['type' => $change->record_type],
            $scope->key('domain', $change->domain_id), source: ['type' => 'dns_change', 'id' => (int) $change->id]))->all();
    }
}
