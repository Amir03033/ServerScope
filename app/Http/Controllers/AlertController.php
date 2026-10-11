<?php

namespace App\Http\Controllers;

use App\Models\Alert;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AlertController extends Controller
{
    public function __invoke(Request $request): View
    {
        $status = $request->query('status', 'active');

        if (! in_array($status, ['active', 'resolved', 'all'], true)) {
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
            ->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")
            ->orderByDesc('triggered_at')
            ->paginate(15)
            ->withQueryString();

        $activeCount = Alert::query()
            ->where('status', 'active')
            ->count();

        $resolvedCount = Alert::query()
            ->where('status', 'resolved')
            ->count();

        return view('alerts.index', [
            'alerts' => $alerts,
            'status' => $status,
            'activeCount' => $activeCount,
            'resolvedCount' => $resolvedCount,
            'totalCount' => $activeCount + $resolvedCount,
        ]);
    }
}
