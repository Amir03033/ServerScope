<?php

namespace App\Http\Controllers;

use App\Models\ServiceCheck;
use App\Services\Incidents\IncidentManager;
use App\Services\ServiceMonitor;
use App\Support\Audit;
use App\Support\Severity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Administrators enable/disable reported services and their alerts.
 */
class ServiceCheckController extends Controller
{
    public function update(Request $request, ServiceCheck $service, IncidentManager $incidents): RedirectResponse
    {
        Gate::authorize('admin');

        $validated = $request->validate([
            'is_enabled' => ['required', 'boolean'],
            'alerts_enabled' => ['required', 'boolean'],
            'severity' => ['required', Rule::in(Severity::ALL)],
            'failure_threshold' => ['required', 'integer', 'between:1,10'],
            'display_name' => ['nullable', 'string', 'max:100'],
        ]);

        $service->fill($validated);
        $changes = Audit::diff($service);
        $service->save();

        // A disabled service has no active problem any more.
        if (! $service->is_enabled) {
            $incidents->recover(ServiceMonitor::fingerprint($service), "Controle van {$service->name} is uitgeschakeld.", notify: false);
        }

        if ($changes !== []) {
            Audit::log('service.updated', "Service {$service->name} op {$service->server?->name} bijgewerkt", $service, ['changes' => $changes]);
        }

        return back()->with('success', 'Service bijgewerkt.');
    }

    public function destroy(ServiceCheck $service, IncidentManager $incidents): RedirectResponse
    {
        Gate::authorize('admin');

        $incidents->recover(ServiceMonitor::fingerprint($service), "Service {$service->name} is verwijderd uit de monitoring.", notify: false);
        $service->incidents()->update(['subject_id' => null, 'subject_type' => null]);
        $service->delete();
        Audit::log('service.deleted', "Service {$service->name} verwijderd", $service->server);

        return back()->with('success', 'Service verwijderd.');
    }
}
