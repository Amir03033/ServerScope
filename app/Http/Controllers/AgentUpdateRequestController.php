<?php

namespace App\Http\Controllers;

use App\Models\AgentRelease;
use App\Models\Server;
use App\Services\AgentUpdates;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * "Bijwerken", "Alles bijwerken" and the automatic-update setting. Team
 * administrators only; servers of other teams do not exist (404). The
 * request only says which signed release; the updater on the server
 * verifies it with its own key.
 */
class AgentUpdateRequestController extends Controller
{
    public function store(Server $server, AgentUpdates $updates): RedirectResponse
    {
        Gate::authorize('admin');

        $release = AgentRelease::latestAvailable();

        if ($problem = $updates->blocker($server, $release)) {
            return back()->with('error', $problem);
        }

        $request = $updates->request($server, $release, request()->user(), 'manual');

        return back()->with('success', $request->wasRecentlyCreated
            ? "Update naar {$release->version} aangevraagd. Het updateprogramma op de server haalt hem binnen enkele minuten op."
            : "Er loopt al een update naar {$request->target_version} voor deze server.");
    }

    public function storeAll(AgentUpdates $updates): RedirectResponse
    {
        Gate::authorize('admin');

        $release = AgentRelease::latestAvailable();
        $count = 0;

        if ($release !== null) {
            Server::query()->orderBy('id')->each(function (Server $server) use ($updates, $release, &$count): void {
                if ($updates->blocker($server, $release) === null && $updates->request($server, $release, request()->user(), 'bulk')->wasRecentlyCreated) {
                    $count++;
                }
            });
        }

        return back()->with('success', $count === 0
            ? 'Er zijn geen servers die nu bijgewerkt kunnen worden.'
            : "Update naar {$release->version} aangevraagd voor {$count} server(s).");
    }

    public function autoUpdate(Request $request, Server $server): RedirectResponse
    {
        Gate::authorize('admin');

        $enabled = $request->boolean('enabled');
        $server->forceFill(['auto_update_enabled' => $enabled])->save();

        Audit::log($enabled ? 'agent_update.auto_enabled' : 'agent_update.auto_disabled', ($enabled ? 'Automatisch bijwerken ingeschakeld' : 'Automatisch bijwerken uitgeschakeld')." voor {$server->name}", $server);

        return back()->with('success', $enabled
            ? 'Automatisch bijwerken staat aan: nieuwe ondertekende releases worden op deze server geïnstalleerd.'
            : 'Automatisch bijwerken staat uit.');
    }
}
