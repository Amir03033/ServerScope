<?php

namespace App\Http\Controllers;

use App\Services\Nexus\GlobeData;
use App\Tenancy\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

/**
 * NEXUS globe: the measuring locations and per website whether it was
 * reachable from each. Every team member can view it; it changes nothing.
 */
class GlobeController extends Controller
{
    public function index(GlobeData $globe): View
    {
        return view('nexus.globe', ['globe' => $globe->forTeam($this->teamId())]);
    }

    public function data(GlobeData $globe): JsonResponse
    {
        return response()->json(['data' => $globe->forTeam($this->teamId())])->setPrivate();
    }

    private function teamId(): int
    {
        abort_unless(Tenant::check(), 403);

        return (int) Tenant::id();
    }
}
