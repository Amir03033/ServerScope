<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StatusPage extends Model
{
    use \App\Tenancy\BelongsToTeam;

    protected $fillable = ['name', 'slug', 'description', 'is_public', 'show_incident_titles', 'show_uptime', 'accent_color', 'created_by'];

    protected function casts(): array
    {
        return ['is_public' => 'boolean', 'show_incident_titles' => 'boolean', 'show_uptime' => 'boolean'];
    }

    public function components(): HasMany
    {
        return $this->hasMany(StatusPageComponent::class)->orderBy('sort_order')->orderBy('id');
    }

    public function getRouteKeyName(): string
    {
        return 'id';
    }
}
