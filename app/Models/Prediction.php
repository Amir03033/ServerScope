<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * An explainable statistical finding: an anomaly against the resource's
 * own baseline, or a capacity forecast from a linear trend. Each row
 * records the metric, window, trend, horizon, uncertainty and reason.
 */
class Prediction extends Model
{
    use \App\Tenancy\BelongsToTeam;

    /** Belongs to the team of the resource it is about. */
    public function owningTeamId(): ?int
    {
        return \App\Tenancy\Tenancy::teamIdOfMorph($this->resource_type, $this->resource_id);
    }

    public const METRIC_LABELS = [
        'cpu_percent' => 'CPU-gebruik',
        'memory_percent' => 'Geheugengebruik',
        'swap_percent' => 'Swapgebruik',
        'load_1' => 'Load average',
        'disk_percent' => 'Schijfgebruik',
        'network_rx' => 'Netwerk inkomend',
        'network_tx' => 'Netwerk uitgaand',
        'response_time_ms' => 'Responstijd',
        'error_rate' => 'Foutpercentage',
    ];

    public const CONFIDENCE_LABELS = ['low' => 'laag', 'medium' => 'gemiddeld', 'high' => 'hoog'];

    protected $fillable = [
        'resource_type', 'resource_id', 'kind', 'metric', 'dimension', 'status', 'active_key', 'severity', 'severity_rank',
        'confidence', 'observed_value', 'baseline_value', 'baseline_spread', 'score', 'slope_per_day', 'r_squared',
        'threshold', 'eta_at', 'eta_low_at', 'eta_high_at', 'horizon_days', 'window_start', 'window_end', 'sample_count',
        'reason', 'details', 'detected_at', 'last_evaluated_at', 'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'observed_value' => 'float',
            'baseline_value' => 'float',
            'baseline_spread' => 'float',
            'score' => 'float',
            'slope_per_day' => 'float',
            'r_squared' => 'float',
            'threshold' => 'float',
            'eta_at' => 'datetime',
            'eta_low_at' => 'datetime',
            'eta_high_at' => 'datetime',
            'window_start' => 'datetime',
            'window_end' => 'datetime',
            'details' => 'array',
            'detected_at' => 'datetime',
            'last_evaluated_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function resource(): MorphTo
    {
        return $this->morphTo();
    }

    public function scopeCurrent(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function scopeForResource(Builder $query, Model $resource): Builder
    {
        return $query->where('resource_type', $resource->getMorphClass())->where('resource_id', $resource->getKey());
    }

    public function metricLabel(): string
    {
        return (self::METRIC_LABELS[$this->metric] ?? $this->metric).($this->dimension ? " ({$this->dimension})" : '');
    }
}
