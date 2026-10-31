<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Invoice extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['paid_at' => 'datetime', 'period_start' => 'datetime', 'period_end' => 'datetime', 'issued_at' => 'datetime',
            'seller' => 'array', 'buyer' => 'array', 'vat_rate' => 'float'];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function isIssued(): bool
    {
        return $this->number !== null;
    }

    public static function money(int $cents, string $currency = 'EUR'): string
    {
        return ($currency === 'EUR' ? '€ ' : $currency.' ').number_format($cents / 100, 2, ',', '.');
    }
}
