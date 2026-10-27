<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceCheckEvent extends Model
{
    public $timestamps = false;

    protected $fillable = ['service_check_id', 'status', 'previous_status', 'detail', 'recorded_at'];

    protected function casts(): array
    {
        return ['recorded_at' => 'datetime'];
    }

    public function serviceCheck(): BelongsTo
    {
        return $this->belongsTo(ServiceCheck::class);
    }
}
