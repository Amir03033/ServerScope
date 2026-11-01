<?php

namespace App\Models;

use App\Tenancy\BelongsToTeam;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/** A personal favourite of one user (server or domain); never shared with the team. */
class Favorite extends Model
{
    use BelongsToTeam;

    public const TYPES = ['server' => Server::class, 'domain' => Domain::class];

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    /** Ids of the user's favourites of one type within the current team. */
    public static function idsFor(?User $user, string $type): array
    {
        if ($user === null) {
            return [];
        }

        return self::query()->where('user_id', $user->id)->where('favoritable_type', $type)->pluck('favoritable_id')->map(fn ($id) => (int) $id)->all();
    }

    /** Limits a server or domain query to the user's favourites. */
    public static function limit(Builder $query, User $user, string $type): Builder
    {
        return $query->whereIn($query->getModel()->getQualifiedKeyName(), DB::table('favorites')->select('favoritable_id')
            ->where('user_id', $user->id)->where('favoritable_type', $type));
    }
}
