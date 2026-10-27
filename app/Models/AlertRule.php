<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class AlertRule extends Model
{
    use SoftDeletes;

    public const METRIC_AGENT_OFFLINE = 'agent_offline';

    /** @var array<string, string> */
    public const METRIC_LABELS = [
        'cpu_percent' => 'CPU-gebruik',
        'memory_percent' => 'RAM-gebruik',
        'disk_percent' => 'Schijfgebruik',
        self::METRIC_AGENT_OFFLINE => 'Agent offline',
    ];

    public static function metricLabel(string $metric): string
    {
        return self::METRIC_LABELS[$metric] ?? str_replace('_', ' ', $metric);
    }

    public static function metricUnit(string $metric): string
    {
        return match ($metric) {
            'cpu_percent', 'memory_percent', 'disk_percent' => '%',
            self::METRIC_AGENT_OFFLINE => ' min',
            default => '',
        };
    }

    protected $fillable = [
        'name',
        'metric',
        'threshold',
        'operator',
        'severity',
        'duration_minutes',
        'recovery_samples',
        'is_enabled',
    ];

    protected function casts(): array
    {
        return [
            'threshold' => 'float',
            'duration_minutes' => 'integer',
            'recovery_samples' => 'integer',
            'is_enabled' => 'boolean',
        ];
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(Alert::class);
    }
}
