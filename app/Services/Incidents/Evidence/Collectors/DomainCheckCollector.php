<?php

namespace App\Services\Incidents\Evidence\Collectors;

use App\Services\Incidents\Evidence\Collector;
use App\Services\Incidents\Evidence\EvidenceRecord;
use App\Services\Incidents\Evidence\EvidenceScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** HTTP, TLS, DNS and expiry checks of the domains in scope: only changes between success and failure. */
final class DomainCheckCollector implements Collector
{
    private const MAX_ROWS = 5000;

    public function name(): string
    {
        return 'domain_checks';
    }

    public function collect(EvidenceScope $scope): array
    {
        if (! isset($scope->byType['domain'])) {
            return [];
        }

        $rows = DB::table('domain_checks')->whereIn('domain_id', array_keys($scope->byType['domain']))
            ->whereBetween('checked_at', [$scope->window['from'], $scope->window['to']])->orderBy('checked_at')->limit(self::MAX_ROWS)
            ->get(['id', 'domain_id', 'type', 'success', 'message', 'error_code', 'checked_at']);

        if ($rows->count() >= self::MAX_ROWS) {
            $scope->truncate($this->name());
        }

        $records = [];

        foreach ($rows as $row) {
            $scope->countCheck($scope->key('domain', $row->domain_id), CarbonImmutable::parse($row->checked_at, 'UTC'), (bool) $row->success);
        }

        Transitions::each($rows, fn ($r) => $r->domain_id.':'.$r->type, function ($row, bool $failing) use (&$records, $scope) {
            $records[] = new EvidenceRecord(CarbonImmutable::parse($row->checked_at, 'UTC'), $failing ? 'check' : 'recovery', $failing ? 'check_failed' : 'check_recovered', ['type' => (string) $row->type, 'error_code' => $failing ? $row->error_code : null],
                $scope->key('domain', $row->domain_id), $failing, $failing ? ($row->message ?? $row->error_code) : null, source: ['type' => 'domain_check', 'id' => (int) $row->id]);
        });

        return $records;
    }
}
