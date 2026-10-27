<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A confirmed, user-defined dependency: the dependent resource needs the
 * dependency to work (e.g. a website depends on the server it runs on).
 */
class ResourceDependency extends Model
{
    protected $fillable = ['dependent_type', 'dependent_id', 'dependency_type', 'dependency_id', 'label', 'notes', 'created_by'];

    public function dependent(): MorphTo
    {
        return $this->morphTo();
    }

    public function dependency(): MorphTo
    {
        return $this->morphTo();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
