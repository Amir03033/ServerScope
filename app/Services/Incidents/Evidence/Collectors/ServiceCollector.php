<?php

namespace App\Services\Incidents\Evidence\Collectors;

use App\Services\Incidents\Evidence\Collector;
use App\Services\Incidents\Evidence\EvidenceRecord;
use App\Services\Incidents\Evidence\EvidenceScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Service state transitions (service_check_events, reached through the in-scope services). */
final class ServiceCollector implements Collector
{
    public const FAILING = ['stopped', 'failed', 'not_found', 'stale'];

    private const MAX_ROWS = 5000;

    public function name(): string
    {
        return 'services';
    }

    public function collect(EvidenceScope $scope): array
    {
        if (! isset($scope->byType['service'])) {
            return [];
        }

        $rows = DB::table('service_check_events')->whereIn('service_check_id', array_keys($scope->byType['service']))
            ->whereBetween('recorded_at', [$scope->window['from'], $scope->window['to']])->orderBy('recorded_at')->limit(self::MAX_ROWS)->get();

        if ($rows->count() >= self::MAX_ROWS) {
            $scope->truncate($this->name());
        }

        $records = [];

        foreach ($rows as $row) {
            $failing = in_array($row->status, self::FAILING, true);
            $records[] = new EvidenceRecord(CarbonImmutable::parse($row->recorded_at, 'UTC'), $failing ? 'service' : 'recovery', 'service', ['from' => $row->previous_status, 'to' => $row->status],
                $scope->key('service', $row->service_check_id), $failing, $row->detail, source: ['type' => 'service_check_event', 'id' => (int) $row->id]);
        }

        return $records;
    }
}
