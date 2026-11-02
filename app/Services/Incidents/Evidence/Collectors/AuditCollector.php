<?php

namespace App\Services\Incidents\Evidence\Collectors;

use App\Models\AuditLog;
use App\Services\Incidents\Evidence\Collector;
use App\Services\Incidents\Evidence\EvidenceRecord;
use App\Services\Incidents\Evidence\EvidenceScope;
use Carbon\CarbonImmutable;

/** Audited configuration changes of the servers, domains and pages in scope. */
final class AuditCollector implements Collector
{
    private const MAX_ROWS = 100;

    public function name(): string
    {
        return 'audit_logs';
    }

    public function collect(EvidenceScope $scope): array
    {
        $records = [];

        foreach ($scope->byType as $type => $items) {
            if (! in_array($type, ['server', 'domain', 'page'], true)) {
                continue;
            }

            $logs = AuditLog::query()->withoutGlobalScopes()->where('team_id', $scope->teamId())->where('auditable_type', $type)->whereIn('auditable_id', array_keys($items))
                ->whereBetween('created_at', [$scope->window['from'], $scope->window['to']])->limit(self::MAX_ROWS)->get();

            if ($logs->count() >= self::MAX_ROWS) {
                $scope->truncate($this->name());
            }

            foreach ($logs as $log) {
                // The description is the audit entry's own (stored) text.
                $records[] = new EvidenceRecord(CarbonImmutable::instance($log->created_at), 'change', 'audit', ['action' => $log->action, 'description' => $log->description],
                    $items[$log->auditable_id]['key'], source: ['type' => 'audit_log', 'id' => (int) $log->id]);
            }
        }

        return $records;
    }
}
