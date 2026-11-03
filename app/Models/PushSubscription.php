<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A browser on which a user turned on push notifications. Belongs to the user, not to a team. */
class PushSubscription extends Model
{
    protected $fillable = ['user_id', 'endpoint', 'endpoint_hash', 'public_key', 'auth_token', 'content_encoding', 'device', 'last_used_at'];

    protected $hidden = ['endpoint', 'public_key', 'auth_token'];

    protected function casts(): array
    {
        return ['last_used_at' => 'datetime'];
    }

    public static function hashOf(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
