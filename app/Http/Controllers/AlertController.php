<?php

namespace App\Http\Controllers;

use App\Models\Alert;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class AlertController extends Controller
{
    public function __invoke(Request $request): View
    {
        $status = $request->query('status', 'active');

        if (! in_array(
            $status,
            ['active', 'resolved', 'disabled', 'all'],
            true,
        )) {
            $status = 'active';
        }

        $alertsQuery = Alert::query()
            ->with([
                'server:id,name,hostname',
                'alertRule:id,name,metric,threshold,operator',
            ]);

        if ($status !== 'all') {
            $alertsQuery->where('status', $status);
        }

        $alerts = $alertsQuery
            ->orderByRaw(
                "CASE
                    WHEN status = 'active' THEN 0
                    WHEN status = 'resolved' THEN 1
                    WHEN status = 'disabled' THEN 2
                    ELSE 3
                END",
            )
            ->orderByDesc('triggered_at')
            ->paginate(15)
            ->withQueryString();

        $activeCount = Alert::query()
            ->where('status', 'active')
            ->count();

        $resolvedCount = Alert::query()
            ->where('status', 'resolved')
            ->count();

        $disabledCount = Alert::query()
            ->where('status', 'disabled')
            ->count();

        $totalCount = $activeCount + $resolvedCount + $disabledCount;

        return view('alerts.index', [
            'alerts' => $alerts,
            'status' => $status,
            'activeCount' => $activeCount,
            'resolvedCount' => $resolvedCount,
            'disabledCount' => $disabledCount,
            'totalCount' => $totalCount,
        ]);
    }

    /** Remove one finished alert from the history. Active alerts resolve themselves first. */
    public function destroy(Alert $alert): RedirectResponse
    {
        abort_unless(Gate::allows('admin'), 403, __('alert_rules.forbidden'));

        if ($alert->isActive()) {
            return back()->with('error', __('alerts.flash.active_cannot_delete'));
        }

        $alert->delete();

        return back()->with('success', __('alerts.flash.deleted'));
    }

    /** Remove all finished alerts (resolved/disabled) of the current team; active ones stay. */
    public function clear(): RedirectResponse
    {
        abort_unless(Gate::allows('admin'), 403, __('alert_rules.forbidden'));

        $count = Alert::query()->where('status', '!=', 'active')->delete();

        return redirect()
            ->route('alerts.index', ['status' => 'all'])
            ->with('success', __('alerts.flash.cleared', ['count' => $count]));
    }
}
