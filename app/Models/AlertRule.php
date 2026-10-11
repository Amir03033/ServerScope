<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class AlertRule extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'metric',
        'threshold',
        'operator',
        'duration_minutes',
        'is_enabled',
    ];

    protected function casts(): array
    {
        return [
            'threshold' => 'float',
            'duration_minutes' => 'integer',
            'is_enabled' => 'boolean',
        ];
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(Alert::class);
    }
}
