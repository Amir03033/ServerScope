<?php

namespace App\Http\Controllers;

use App\Services\Reports\AvailabilityReport;
use App\Support\Time;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function index(Request $request, AvailabilityReport $reports): View
    {
        return view('reports.index', ['report' => $reports->build(...$this->period($request)), 'input' => $request->query()]);
    }

    public function export(Request $request, AvailabilityReport $reports): StreamedResponse
    {
        $report = $reports->build(...$this->period($request));
        $filename = 'serverscope-beschikbaarheid-'.$report['from']->format('Ymd').'-'.$report['to']->format('Ymd').'.csv';

        return response()->streamDownload(function () use ($report): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['type', 'naam', 'uptime_pct', 'dekking_pct', 'downtime_min', 'gem_responstijd_ms', 'incidenten', 'controles_of_metingen', 'haalt_doel']);

            foreach ($report['domains'] as $row) {
                fputcsv($out, ['domein', self::safe($row['domain']->displayName()), $row['uptime'], $row['coverage'], $row['downtime_minutes'], $row['avg_ms'], $row['incidents'], $row['checks'], self::bool($row['meets_target'])]);
            }

            foreach ($report['servers'] as $row) {
                fputcsv($out, ['server', self::safe($row['server']->name), $row['uptime'], $row['coverage'], $row['downtime_minutes'], null, $row['incidents'], $row['samples'], self::bool($row['meets_target'])]);
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: float}
     */
    private function period(Request $request): array
    {
        $validated = $request->validate([
            'period' => ['nullable', Rule::in(['7d', '30d', '90d', 'month', 'custom'])],
            'from' => ['nullable', 'required_if:period,custom', 'date'],
            'to' => ['nullable', 'required_if:period,custom', 'date', 'after:from'],
            'target' => ['nullable', 'numeric', 'between:90,100'],
        ]);

        $now = CarbonImmutable::now();
        $target = (float) ($validated['target'] ?? config('serverscope.reports.default_target', 99.9));

        [$from, $to] = match ($validated['period'] ?? '30d') {
            '7d' => [$now->subDays(7), $now],
            '90d' => [$now->subDays(90), $now],
            'month' => [CarbonImmutable::now(Time::zone())->startOfMonth()->utc(), $now],
            'custom' => [Time::parse($validated['from'])->startOfMinute(), Time::parse($validated['to'])->endOfMinute()->min($now)],
            default => [$now->subDays(30), $now],
        };

        if ($from->diffInDays($to) > 366) {
            abort(422, 'De rapportageperiode mag maximaal een jaar zijn.');
        }

        return [$from, $to, $target];
    }

    /** Prevent CSV formula injection in spreadsheet programs. */
    private static function safe(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value;
    }

    private static function bool(?bool $value): string
    {
        return $value === null ? '' : ($value ? 'ja' : 'nee');
    }
}
