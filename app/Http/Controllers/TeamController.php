<?php

namespace App\Http\Controllers;

use App\Models\Team;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TeamController extends Controller
{
    /** Switch to another team the user is a member of. */
    public function switch(Request $request, Team $team): RedirectResponse
    {
        $user = $request->user();

        // Teams the user does not belong to do not exist for them.
        abort_unless($user->belongsToTeam($team), 404);

        $user->forceFill(['current_team_id' => $team->id]);
        $user->syncAdminMirror(quietly: true);
        $user->saveQuietly();
        \App\Tenancy\Tenant::run($team, fn () => Audit::log('team.switched', "Gewisseld naar team {$team->name}"));

        return redirect()->route('dashboard')->with('success', "Je werkt nu in team {$team->name}.");
    }
}
