<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebhookDelivery extends Model
{
    use \App\Tenancy\ScopedThroughParent;

    /** @return array{0: string, 1: class-string} */
    protected static function teamParent(): array
    {
        return ['webhook_endpoint_id', WebhookEndpoint::class];
    }

    protected $fillable = ['uuid', 'webhook_endpoint_id', 'event', 'payload', 'status', 'attempts', 'response_status', 'response_body', 'error', 'duration_ms', 'delivered_at'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'delivered_at' => 'datetime'];
    }

    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(WebhookEndpoint::class, 'webhook_endpoint_id');
    }
}
