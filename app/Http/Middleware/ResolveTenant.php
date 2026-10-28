<?php

namespace App\Http\Middleware;

use App\Tenancy\Tenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sets the tenant for a signed-in web user. users.current_team_id is
 * never trusted on its own: membership is verified on every request.
 * A user who is not (or no longer) a member of that team is signed out.
 * Runs before route model binding, so IDs of other teams resolve to 404.
 */
class ResolveTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        $teamId = $user->current_team_id;

        if ($teamId === null) {
            // First request after the user lost their current team (or was
            // created without one): use their first membership.
            $teamId = $user->teams()->orderBy('teams.id')->value('teams.id');

            if ($teamId !== null) {
                $user->forceFill(['current_team_id' => $teamId]);
                $user->syncAdminMirror(quietly: true);
                $user->saveQuietly();
            }
        } elseif (! $user->belongsToTeam($teamId)) {
            $teamId = null;
        }

        if ($teamId === null) {
            return $this->deny($request);
        }

        return Tenant::run((int) $teamId, fn () => $next($request));
    }

    private function deny(Request $request): Response
    {
        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        $message = 'Je hebt geen toegang (meer) tot dit team. Log opnieuw in of neem contact op met je beheerder.';

        return $request->expectsJson()
            ? response()->json(['message' => $message], 403)
            : redirect()->route('login')->with('error', $message);
    }
}
