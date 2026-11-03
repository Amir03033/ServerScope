<?php

namespace App\Http\Controllers;

use App\Services\Live\LivePulse;
use App\Tenancy\Tenant;
use Illuminate\Http\JsonResponse;

/** The team's pulse for the live logo and tab icon (polled by every page). */
class LiveController extends Controller
{
    public function pulse(LivePulse $pulse): JsonResponse
    {
        abort_unless(Tenant::check(), 403);

        return response()->json(['data' => $pulse->forTeam((int) Tenant::id())])
            ->header('Cache-Control', 'private, no-store');
    }
}
