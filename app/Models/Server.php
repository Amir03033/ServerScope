<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Server extends Model
{
    protected $fillable = [
        'name',
        'hostname',
        'ip_address',
        'operating_system',
        'agent_version',
        'status',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
        ];
    }

    public function agentCredentials(): HasMany
    {
        return $this->hasMany(AgentCredential::class);
    }

    public function metricSamples(): HasMany
    {
        return $this->hasMany(MetricSample::class);
    }

    public function latestMetricSample(): HasOne
    {
        return $this->hasOne(MetricSample::class)
            ->latestOfMany('collected_at');
    }
}
