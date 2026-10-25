<?php

namespace App\Http\Controllers;

use App\Models\Server;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ServerMaintenanceController extends Controller
{
    /**
     * Start maintenance now for a fixed duration.
     */
    public function store(Request $request, Server $server): RedirectResponse
    {
        Gate::authorize('admin');

        $validated = $request->validate([
            'duration_minutes' => ['required', 'integer', 'between:5,10080'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $server->forceFill([
            'maintenance_starts_at' => now(),
            'maintenance_ends_at' => now()->addMinutes((int) $validated['duration_minutes']),
            'maintenance_reason' => $validated['reason'] ?? null,
        ])->save();

        Audit::log('server.maintenance_started', "Onderhoud gestart voor {$server->name}", $server, [
            'until' => $server->maintenance_ends_at->toIso8601String(),
            'reason' => $server->maintenance_reason,
        ]);

        return back()->with('success', 'Onderhoudsmodus ingeschakeld. Er worden geen nieuwe downtime-alerts aangemaakt tot '.\App\Support\Time::format($server->maintenance_ends_at).'.');
    }

    /**
     * End (or cancel a scheduled) maintenance window immediately.
     */
    public function destroy(Server $server): RedirectResponse
    {
        Gate::authorize('admin');

        $server->forceFill([
            'maintenance_starts_at' => null,
            'maintenance_ends_at' => null,
            'maintenance_reason' => null,
        ])->save();

        Audit::log('server.maintenance_ended', "Onderhoud beëindigd voor {$server->name}", $server);

        return back()->with('success', 'Onderhoudsmodus beëindigd. Monitoring en alerts zijn weer volledig actief.');
    }
}
