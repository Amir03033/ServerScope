<?php

namespace App\Http\Controllers;

use App\Models\Incident;
use App\Services\Reports\AvailabilityReport;
use App\Support\Time;
use App\Tenancy\Tenant;
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

    /** Printable report (use the browser's "Save as PDF"). */
    public function print(Request $request, AvailabilityReport $reports): View
    {
        return view('reports.print', ['report' => $reports->build(...$this->period($request))]);
    }

    public function export(Request $request, AvailabilityReport $reports): StreamedResponse
    {
        if ($request->query('kind') === 'incidents') {
            return $this->exportIncidents($request);
        }

        $report = $reports->build(...$this->period($request));
        $filename = 'serverscope-beschikbaarheid-'.$report['from']->format('Ymd').'-'.$report['to']->format('Ymd').'.csv';

        return response()->streamDownload(function () use ($report): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['type', 'naam', 'uptime_pct', 'dekking_pct', 'downtime_min', 'gem_responstijd_ms', 'incidenten', 'controles_of_metingen', 'haalt_doel', 'doel_pct']);

            foreach ($report['domains'] as $row) {
                fputcsv($out, ['domein', self::safe($row['domain']->displayName()), $row['uptime'], $row['coverage'], $row['downtime_minutes'], $row['avg_ms'], $row['incidents'], $row['checks'], self::bool($row['meets_target']), $row['target']]);
            }

            foreach ($report['servers'] as $row) {
                fputcsv($out, ['server', self::safe($row['server']->name), $row['uptime'], $row['coverage'], $row['downtime_minutes'], null, $row['incidents'], $row['samples'], self::bool($row['meets_target']), $row['target']]);
            }

            foreach ($report['pages'] as $row) {
                fputcsv($out, ['pagina', self::safe($row['page']->url), $row['uptime'], $row['coverage'], $row['downtime_minutes'], $row['avg_ms'], $row['incidents'], $row['checks'], self::bool($row['meets_target']), $row['target']]);
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** One row per incident opened in the period (frequency and duration analysis). */
    private function exportIncidents(Request $request): StreamedResponse
    {
        [$from, $to] = $this->period($request);
        $filename = 'serverscope-incidenten-'.$from->format('Ymd').'-'.$to->format('Ymd').'.csv';
        // The body is streamed after the middleware has finished, so the
        // tenant is captured here and set again while streaming.
        $teamId = Tenant::id() ?? abort(403);

        return response()->streamDownload(fn () => Tenant::run($teamId, function () use ($from, $to): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['id', 'titel', 'ernst', 'bron', 'status', 'betreft', 'begonnen', 'geopend', 'bevestigd', 'opgelost', 'detectie_s', 'tot_bevestiging_s', 'tot_oplossing_s', 'heropend', 'hoofdincident']);

            Incident::query()->with('subject')->whereBetween('opened_at', [$from, $to])->orderBy('opened_at')->chunk(500, function ($incidents) use ($out): void {
                foreach ($incidents as $incident) {
                    fputcsv($out, [
                        $incident->id, self::safe($incident->title), $incident->severity, $incident->source, $incident->status, self::safe($incident->subjectName()),
                        $incident->started_at?->toIso8601String(), $incident->opened_at->toIso8601String(), $incident->acknowledged_at?->toIso8601String(), $incident->resolved_at?->toIso8601String(),
                        $incident->timeToDetect(), $incident->timeToAcknowledge(), $incident->timeToResolve(), $incident->reopened_count, $incident->parent_id,
                    ]);
                }
            });

            fclose($out);
        }), $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
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
