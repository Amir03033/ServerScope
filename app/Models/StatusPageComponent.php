<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class StatusPageComponent extends Model
{
    protected $fillable = ['status_page_id', 'display_name', 'description', 'subject_type', 'subject_id', 'sort_order'];

    public function statusPage(): BelongsTo
    {
        return $this->belongsTo(StatusPage::class);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo()->withTrashed();
    }
}
