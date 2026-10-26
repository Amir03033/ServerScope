<?php

namespace App\Services\Analytics;

use App\Models\Domain;
use App\Models\Prediction;
use App\Models\Server;
use App\Services\Incidents\IncidentManager;
use App\Services\Incidents\Signal;
use App\Support\Severity;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Explainable statistics instead of black-box machine learning.
 *
 * Anomalies: the mean of the last hour is compared with the resource's
 * own hourly means of the previous 7 days using a robust z-score
 * (median and MAD). A finding needs a high score AND a minimum absolute
 * (and for some metrics relative) change, so small deviations are never
 * reported. At least 72 hourly values are required.
 *
 * Forecasts: a least-squares line through hourly means (disk: 14 days,
 * memory: 7 days). Only rising trends with R² >= 0.5, at least 48 points
 * spanning 3 days, and a crossing within 180 days are kept. The
 * uncertainty band uses the slope ± 2 standard errors (indicative:
 * hourly values are not independent).
 */
class PredictiveAnalyzer
{
    public const SENSITIVITY = ['low' => 6.0, 'medium' => 4.5, 'high' => 3.5];

    public const MIN_BASELINE_HOURS = 72;

    public const MIN_FORECAST_POINTS = 48;

    public const MIN_FORECAST_SPAN_DAYS = 3;

    public const MAX_HORIZON_DAYS = 180;

    /** metric => [absolute minimum change, MAD floor, minimum ratio to baseline] */
    private const ANOMALY_RULES = [
        'cpu_percent' => [15.0, 2.0, null],
        'memory_percent' => [10.0, 1.0, null],
        'swap_percent' => [10.0, 1.0, null],
        'load_1' => [1.0, 0.1, 1.5],
        'network_rx' => [100.0, 5.0, 3.0],
        'network_tx' => [100.0, 5.0, 3.0],
    ];

    /** @var array<int, string> active keys produced in this run */
    private array $produced = [];

    public function __construct(
        private MetricHistory $history,
        private IncidentManager $incidents,
    ) {}

    public static function key(Model $resource, string $kind, string $metric, ?string $dimension): string
    {
        return mb_substr(implode(':', [$resource->getMorphClass(), $resource->getKey(), $kind, $metric, $dimension ?? '-']), 0, 191);
    }

    /** @return array{anomalies: int, forecasts: int} */
    public function analyzeServer(Server $server, ?CarbonInterface $now = null): array
    {
        $now ??= now();
        $this->produced = [];
        $sensitivity = $server->anomaly_sensitivity ?? 'medium';

        $baselineStart = $now->copy()->subDays(7)->subHour();
        $recentStart = $now->copy()->subHour();
        $hourly = $this->history->hourly($server, $baselineStart, $recentStart);
        $recent = $this->history->summary($server, $recentStart, $now);
        $counts = ['anomalies' => 0, 'forecasts' => 0];

        if ($sensitivity !== 'off' && isset(self::SENSITIVITY[$sensitivity])) {
            foreach (self::ANOMALY_RULES as $metric => $rule) {
                if (isset($hourly[$metric], $recent[$metric]) && $this->anomaly($server, $metric, null, array_values($hourly[$metric]), $recent[$metric], $rule, self::SENSITIVITY[$sensitivity], $baselineStart, $now)) {
                    $counts['anomalies']++;
                }
            }

            foreach ($hourly as $metric => $series) {
                if (str_starts_with($metric, 'disk_percent:') && $this->diskGrowthAnomaly($server, substr($metric, 13), $series, $recent[$metric]['mean'] ?? null, self::SENSITIVITY[$sensitivity], $baselineStart, $now)) {
                    $counts['anomalies']++;
                }
            }
        }

        $diskHistory = $this->history->hourly($server, $now->copy()->subDays(14), $now);

        foreach ($diskHistory as $metric => $series) {
            if (str_starts_with($metric, 'disk_percent:') && $this->forecast($server, 'disk_percent', substr($metric, 13), $series, 100.0, $now)) {
                $counts['forecasts']++;
            }
        }

        $memory = $this->history->hourly($server, $now->copy()->subDays(7), $now)['memory_percent'] ?? [];

        if ($memory !== [] && $this->forecast($server, 'memory_percent', null, $memory, 95.0, $now, minSlope: 0.5)) {
            $counts['forecasts']++;
        }

        $this->resolveOthers($server);

        return $counts;
    }

    /**
     * Website response time and HTTP error rate, from the domain's own
     * HTTP checks and the checks of its monitored pages.
     *
     * @return array{anomalies: int, forecasts: int}
     */
    public function analyzeDomain(Domain $domain, ?CarbonInterface $now = null): array
    {
        $now ??= now();
        $this->produced = [];
        $baselineStart = $now->copy()->subDays(7)->subHour();
        $recentStart = $now->copy()->subHour();
        $anomalies = 0;

        $rows = DB::query()->fromSub(
            DB::table('domain_checks')->where('domain_id', $domain->id)->where('type', 'http')->where('checked_at', '>=', $baselineStart)
                ->select(['checked_at', 'success', 'response_time_ms'])
                ->unionAll(DB::table('page_checks')->where('domain_id', $domain->id)->where('checked_at', '>=', $baselineStart)->select(['checked_at', 'success', 'response_time_ms'])),
            'checks',
        )->orderBy('checked_at')->get();

        $hours = [];
        $recent = ['n' => 0, 'fail' => 0, 'times' => []];

        foreach ($rows as $row) {
            $at = Carbon::parse($row->checked_at, 'UTC');

            if ($at->gte($recentStart)) {
                $recent['n']++;
                $recent['fail'] += $row->success ? 0 : 1;

                if ($row->success && $row->response_time_ms !== null) {
                    $recent['times'][] = (float) $row->response_time_ms;
                }

                continue;
            }

            $hour = $at->copy()->startOfHour()->getTimestamp();
            $hours[$hour]['n'] = ($hours[$hour]['n'] ?? 0) + 1;
            $hours[$hour]['fail'] = ($hours[$hour]['fail'] ?? 0) + ($row->success ? 0 : 1);

            if ($row->success && $row->response_time_ms !== null) {
                $hours[$hour]['times'][] = (float) $row->response_time_ms;
            }
        }

        $threshold = self::SENSITIVITY['medium'];
        $responseBaseline = array_values(array_map(fn ($h) => array_sum($h['times']) / count($h['times']), array_filter($hours, fn ($h) => ! empty($h['times']))));

        if (count($recent['times']) >= 3 && $this->anomaly($domain, 'response_time_ms', null, $responseBaseline, ['mean' => array_sum($recent['times']) / count($recent['times']), 'count' => count($recent['times'])], [200.0, 20.0, 1.5], $threshold, $baselineStart, $now, minRecent: 3)) {
            $anomalies++;
        }

        $errorBaseline = array_values(array_map(fn ($h) => $h['fail'] / $h['n'] * 100, array_filter($hours, fn ($h) => $h['n'] >= 1)));

        if ($recent['n'] >= 5 && $this->anomaly($domain, 'error_rate', null, $errorBaseline, ['mean' => $recent['fail'] / $recent['n'] * 100, 'count' => $recent['n']], [20.0, 2.0, null], $threshold, $baselineStart, $now, minRecent: 5)) {
            $anomalies++;
        }

        $this->resolveOthers($domain);

        return ['anomalies' => $anomalies, 'forecasts' => 0];
    }

    /**
     * @param  array<int, float>  $baseline  hourly means
     * @param  array{mean: float, count: int}  $recent
     * @param  array{0: float, 1: float, 2: ?float}  $rule
     */
    private function anomaly(Model $resource, string $metric, ?string $dimension, array $baseline, array $recent, array $rule, float $threshold, CarbonInterface $from, CarbonInterface $now, int $minRecent = 5): bool
    {
        if (count($baseline) < self::MIN_BASELINE_HOURS || $recent['count'] < $minRecent) {
            return false;
        }

        [$minDelta, $floor, $minRatio] = $rule;
        $median = Statistics::median($baseline);
        $mad = Statistics::mad($baseline);
        $value = $recent['mean'];
        $z = Statistics::robustZ($value, $median, $mad, $floor);

        // Only increases matter here, and only when they are large in
        // absolute (and relative) terms as well as statistically.
        if ($z < $threshold || $value - $median < $minDelta || ($minRatio !== null && $value < $median * $minRatio)) {
            return false;
        }

        $label = Prediction::METRIC_LABELS[$metric] ?? $metric;
        $unit = $this->unit($metric);
        $severity = $z >= $threshold * 2 ? Severity::MEDIUM : Severity::LOW;

        $this->store($resource, 'anomaly', $metric, $dimension, [
            'severity' => $severity,
            'observed_value' => round($value, 2),
            'baseline_value' => round($median, 2),
            'baseline_spread' => round((float) $mad, 3),
            'score' => round($z, 2),
            'window_start' => $from,
            'window_end' => $now,
            'sample_count' => count($baseline),
            'confidence' => $z >= $threshold * 2 ? 'high' : 'medium',
            'reason' => sprintf(
                '%s was het afgelopen uur gemiddeld %s%s, tegenover een mediaan van %s%s (MAD %s) over %d uurgemiddelden van de 7 dagen ervoor. Robuuste z-score %s (drempel %s).',
                $label, $this->number($value), $unit, $this->number($median), $unit, $this->number((float) $mad), count($baseline), $this->number($z), $this->number($threshold),
            ),
        ], $now);

        return true;
    }

    /**
     * Abnormal disk consumption: growth over the last 6 hours compared with
     * the growth of earlier 6-hour periods.
     *
     * @param  array<int, float>  $series  hourly means keyed by timestamp
     */
    private function diskGrowthAnomaly(Server $server, string $mount, array $series, ?float $current, float $threshold, CarbonInterface $from, CarbonInterface $now): bool
    {
        if ($current === null || count($series) < self::MIN_BASELINE_HOURS) {
            return false;
        }

        $values = array_values($series);
        $deltas = [];

        for ($i = 6; $i < count($values); $i++) {
            $deltas[] = $values[$i] - $values[$i - 6];
        }

        $recentDelta = $current - $values[max(0, count($values) - 6)];
        $median = Statistics::median($deltas);
        $mad = Statistics::mad($deltas);
        $z = Statistics::robustZ($recentDelta, $median, $mad, 0.5);

        if ($z < $threshold || $recentDelta < 5.0) {
            return false;
        }

        $this->store($server, 'anomaly', 'disk_percent', $mount, [
            'severity' => Severity::MEDIUM,
            'observed_value' => round($recentDelta, 2),
            'baseline_value' => round($median, 2),
            'baseline_spread' => round((float) $mad, 3),
            'score' => round($z, 2),
            'window_start' => $from,
            'window_end' => $now,
            'sample_count' => count($deltas),
            'confidence' => 'medium',
            'reason' => sprintf(
                'Schijfgebruik op %s steeg ongeveer %s procentpunt in 6 uur, terwijl de gebruikelijke 6-uursgroei %s procentpunt is (MAD %s, %d perioden). Robuuste z-score %s.',
                $mount, $this->number($recentDelta), $this->number($median), $this->number((float) $mad), count($deltas), $this->number($z),
            ),
        ], $now);

        return true;
    }

    /** @param  array<int, float>  $series  hourly means keyed by timestamp */
    private function forecast(Server $server, string $metric, ?string $dimension, array $series, float $limit, CarbonInterface $now, float $minSlope = 0.05): bool
    {
        if (count($series) < self::MIN_FORECAST_POINTS) {
            return false;
        }

        $first = array_key_first($series);
        $last = array_key_last($series);

        if (($last - $first) / 86400 < self::MIN_FORECAST_SPAN_DAYS) {
            return false;
        }

        $points = [];

        foreach ($series as $timestamp => $value) {
            $points[] = [($timestamp - $first) / 86400, (float) $value];
        }

        $fit = Statistics::linearRegression($points);

        if ($fit === null || $fit['slope'] < $minSlope || $fit['r_squared'] < 0.5) {
            return false;
        }

        $nowX = ($now->getTimestamp() - $first) / 86400;
        $current = min($limit, $fit['intercept'] + $fit['slope'] * $nowX);
        $days = ($limit - $current) / $fit['slope'];

        if ($days > self::MAX_HORIZON_DAYS) {
            return false;
        }

        $fast = $fit['slope'] + 2 * $fit['slope_se'];
        $slow = $fit['slope'] - 2 * $fit['slope_se'];
        $relativeError = $fit['slope_se'] / $fit['slope'];
        $confidence = match (true) {
            $fit['r_squared'] >= 0.8 && $relativeError < 0.2 => 'high',
            $relativeError < 0.5 => 'medium',
            default => 'low',
        };
        $eta = $now->copy()->addSeconds((int) round(max(0, $days) * 86400));
        $label = Prediction::METRIC_LABELS[$metric] ?? $metric;

        $this->store($server, 'forecast', $metric, $dimension, [
            'severity' => $days <= 2 ? Severity::HIGH : ($days <= 14 ? Severity::MEDIUM : Severity::LOW),
            'observed_value' => round($current, 2),
            'slope_per_day' => round($fit['slope'], 4),
            'r_squared' => round($fit['r_squared'], 3),
            'threshold' => $limit,
            'eta_at' => $eta,
            'eta_low_at' => $now->copy()->addSeconds((int) round(max(0, ($limit - $current) / $fast) * 86400)),
            'eta_high_at' => $slow > 0 ? $now->copy()->addSeconds((int) round(min(self::MAX_HORIZON_DAYS * 3, ($limit - $current) / $slow) * 86400)) : null,
            'horizon_days' => (int) ceil(max(0, $days)),
            'window_start' => Carbon::createFromTimestamp($first),
            'window_end' => $now,
            'sample_count' => count($points),
            'confidence' => $confidence,
            'reason' => sprintf(
                '%s%s stijgt met %s procentpunt per dag (lineaire trend over %d uurgemiddelden van %s dagen, R² %s). Bij gelijkblijvende trend wordt %s%% bereikt over ongeveer %s dagen%s.',
                $label, $dimension ? " op {$dimension}" : '', $this->number($fit['slope']), count($points), $this->number(($last - $first) / 86400, 1),
                $this->number($fit['r_squared']), $this->number($limit, 0), $this->number(max(0, $days), 1),
                $slow > 0 ? sprintf(' (bandbreedte %s–%s dagen)', $this->number(max(0, ($limit - $current) / $fast), 1), $this->number(($limit - $current) / $slow, 1)) : ' (onzekerheid te groot voor een bovengrens)',
            ),
        ], $now);

        return true;
    }

    private function store(Model $resource, string $kind, string $metric, ?string $dimension, array $attributes, CarbonInterface $now): Prediction
    {
        $key = self::key($resource, $kind, $metric, $dimension);
        $this->produced[] = $key;

        $prediction = Prediction::query()->firstOrNew(['active_key' => $key]);
        $isNew = ! $prediction->exists;

        $prediction->fill([
            ...$attributes,
            'resource_type' => $resource->getMorphClass(),
            'resource_id' => $resource->getKey(),
            'kind' => $kind,
            'metric' => $metric,
            'dimension' => $dimension,
            'status' => 'active',
            'severity_rank' => Severity::rank($attributes['severity']),
            'detected_at' => $isNew ? $now : $prediction->detected_at,
            'last_evaluated_at' => $now,
        ])->save();

        $this->maybeAlert($resource, $prediction);

        return $prediction;
    }

    /**
     * Predictive incidents are opt-out per server and globally; anomalies
     * only open incidents when explicitly enabled, forecasts only when
     * the crossing is near and the confidence is at least medium.
     */
    private function maybeAlert(Model $resource, Prediction $prediction): void
    {
        if (! config('serverscope.predictive.alerts_enabled', true) || ($resource instanceof Server && ! $resource->predictive_alerts_enabled)) {
            return;
        }

        $isForecast = $prediction->kind === 'forecast';
        $wanted = $isForecast
            ? $prediction->horizon_days !== null && $prediction->horizon_days <= (int) config('serverscope.predictive.alert_horizon_days', 7) && in_array($prediction->confidence, ['medium', 'high'], true)
            : config('serverscope.predictive.anomaly_incidents', false) && Severity::atLeast($prediction->severity, Severity::MEDIUM);

        $fingerprint = 'prediction:'.$prediction->active_key;

        if (! $wanted) {
            $this->incidents->recover($fingerprint, 'De voorspelling voldoet niet meer aan de meldingsdrempel.', notify: false);

            return;
        }

        $name = method_exists($resource, 'displayName') ? $resource->displayName() : (string) $resource->getAttribute('name');

        $this->incidents->trigger(new Signal(
            fingerprint: $fingerprint,
            source: $isForecast ? 'predictive' : 'anomaly',
            severity: $isForecast ? Severity::MEDIUM : $prediction->severity,
            title: $isForecast
                ? "{$prediction->metricLabel()} op {$name} bereikt naar verwachting {$this->number((float) $prediction->threshold, 0)}% over ~{$prediction->horizon_days} dagen"
                : "Afwijkend: {$prediction->metricLabel()} op {$name}",
            description: $prediction->reason.' Dit is een statistische voorspelling, geen vastgestelde storing.',
            subject: $resource,
            context: ['prediction_id' => $prediction->id, 'confidence' => $prediction->confidence],
        ));
    }

    /** Resolve earlier findings for the resource that this run did not confirm. */
    private function resolveOthers(Model $resource): void
    {
        $stale = Prediction::query()->forResource($resource)->current()->whereNotIn('active_key', $this->produced ?: ['-'])->get();

        foreach ($stale as $prediction) {
            $this->incidents->recover('prediction:'.$prediction->active_key, 'De afwijking of trend is niet meer aanwezig.', notify: false);
            $prediction->forceFill(['status' => 'resolved', 'active_key' => null, 'resolved_at' => now()])->save();
        }
    }

    private function unit(string $metric): string
    {
        return match ($metric) {
            'cpu_percent', 'memory_percent', 'swap_percent', 'disk_percent', 'error_rate' => '%',
            'network_rx', 'network_tx' => ' KiB/s',
            'response_time_ms' => ' ms',
            default => '',
        };
    }

    private function number(float $value, int $decimals = 2): string
    {
        return number_format($value, $decimals, ',', '.');
    }
}
