<?php

namespace App\Http\Controllers;

use App\Models\Favorite;
use App\Tenancy\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Adds or removes a personal favourite. The resource is looked up through
 * its tenant-scoped model, so another team's server or domain is a 404.
 * JSON for the in-page toggle, a redirect without JavaScript.
 */
class FavoriteController extends Controller
{
    public function toggle(Request $request): JsonResponse|RedirectResponse
    {
        $v = $request->validate([
            'type' => ['required', Rule::in(array_keys(Favorite::TYPES))],
            'id' => ['required', 'integer', 'min:1'],
        ]);

        $model = Favorite::TYPES[$v['type']];
        abort_unless($model::query()->whereKey($v['id'])->exists(), 404);

        $attributes = ['user_id' => $request->user()->id, 'favoritable_type' => $v['type'], 'favoritable_id' => (int) $v['id']];
        $existing = Favorite::query()->where($attributes)->first();

        if ($existing !== null) {
            $existing->delete();
            $favorite = false;
        } else {
            // insertOrIgnore: a double click cannot create two rows (unique key).
            Favorite::query()->insertOrIgnore([...$attributes, 'team_id' => Tenant::id(), 'created_at' => now()]);
            $favorite = true;
        }

        if ($request->expectsJson()) {
            return response()->json(['favorite' => $favorite, 'label' => __($favorite ? 'favorites.remove' : 'favorites.add')]);
        }

        return back()->with('success', __($favorite ? 'favorites.added' : 'favorites.removed'));
    }
}
