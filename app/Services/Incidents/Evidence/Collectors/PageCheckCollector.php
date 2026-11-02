<?php

namespace App\Services\Incidents\Evidence\Collectors;

use App\Services\Incidents\Evidence\Collector;
use App\Services\Incidents\Evidence\EvidenceRecord;
use App\Services\Incidents\Evidence\EvidenceScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Page checks of the pages in scope: only changes between success and failure. */
final class PageCheckCollector implements Collector
{
    private const MAX_ROWS = 5000;

    public function name(): string
    {
        return 'page_checks';
    }

    public function collect(EvidenceScope $scope): array
    {
        if (! isset($scope->byType['page'])) {
            return [];
        }

        $rows = DB::table('page_checks')->whereIn('monitored_page_id', array_keys($scope->byType['page']))
            ->whereBetween('checked_at', [$scope->window['from'], $scope->window['to']])->orderBy('checked_at')->limit(self::MAX_ROWS)
            ->get(['id', 'monitored_page_id', 'success', 'failure_type', 'message', 'checked_at']);

        if ($rows->count() >= self::MAX_ROWS) {
            $scope->truncate($this->name());
        }

        $records = [];

        foreach ($rows as $row) {
            $scope->countCheck($scope->key('page', $row->monitored_page_id), CarbonImmutable::parse($row->checked_at, 'UTC'), (bool) $row->success);
        }

        Transitions::each($rows, fn ($r) => (string) $r->monitored_page_id, function ($row, bool $failing) use (&$records, $scope) {
            $records[] = new EvidenceRecord(CarbonImmutable::parse($row->checked_at, 'UTC'), $failing ? 'check' : 'recovery', $failing ? 'check_failed' : 'check_recovered', ['type' => 'page', 'failure_type' => $failing ? $row->failure_type : null],
                $scope->key('page', $row->monitored_page_id), $failing, $failing ? ($row->message ?? $row->failure_type) : null, source: ['type' => 'page_check', 'id' => (int) $row->id]);
        });

        return $records;
    }
}
