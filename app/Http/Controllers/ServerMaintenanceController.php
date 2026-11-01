<?php

namespace App\Http\Controllers;

use App\Models\Server;
use App\Support\Audit;
use App\Support\Time;
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
            // A preset duration, or "custom": an end time in the display timezone.
            'duration_minutes' => ['required', 'in:30,60,240,1440,custom'],
            'ends_at' => ['nullable', 'required_if:duration_minutes,custom', 'string', 'max:30'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        if ($validated['duration_minutes'] === 'custom') {
            $endsAt = Time::parse($validated['ends_at'] ?? null);

            // Not in the past, not shorter than 5 minutes, at most 7 days.
            if ($endsAt === null || $endsAt->lt(now()->addMinutes(5)) || $endsAt->gt(now()->addDays(7))) {
                return back()->withInput()->withErrors(['ends_at' => __('servers.show.maintenance_invalid_end')]);
            }
        } else {
            $endsAt = now()->addMinutes((int) $validated['duration_minutes']);
        }

        $server->forceFill([
            'maintenance_starts_at' => now(),
            'maintenance_ends_at' => $endsAt,
            'maintenance_reason' => $validated['reason'] ?? null,
        ])->save();

        Audit::log('server.maintenance_started', "Onderhoud gestart voor {$server->name}", $server, [
            'until' => $server->maintenance_ends_at->toIso8601String(),
            'reason' => $server->maintenance_reason,
        ]);

        return back()->with('success', __('servers.flash.maintenance_started', ['date' => Time::dateTime($server->maintenance_ends_at)]));
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

        return back()->with('success', __('servers.flash.maintenance_ended'));
    }
}
