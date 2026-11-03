<?php

namespace App\Http\Controllers;

use App\Services\Live\LivePulse;
use App\Services\Live\MissionControl;
use App\Tenancy\Tenant;
use Illuminate\Http\JsonResponse;

/** The team's pulse for the live logo and tab icon (polled by every page). */
class LiveController extends Controller
{
    /** Mission control on the dashboard: score, tiles and the last 24 hours (polled). */
    public function mission(MissionControl $mission): JsonResponse
    {
        abort_unless(Tenant::check(), 403);

        return response()->json(['data' => $mission->forTeam((int) Tenant::id())])
            ->header('Cache-Control', 'private, no-store');
    }

    public function pulse(LivePulse $pulse): JsonResponse
    {
        abort_unless(Tenant::check(), 403);

        return response()->json(['data' => $pulse->forTeam((int) Tenant::id())])
            ->header('Cache-Control', 'private, no-store');
    }
}
