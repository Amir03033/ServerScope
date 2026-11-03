<?php

namespace App\Http\Controllers;

use App\Models\Team;
use App\Services\Nexus\GlobeData;
use App\Services\Nexus\TimelapseData;
use App\Services\Nexus\TopologyBuilder;
use App\Tenancy\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * NEXUS: the infrastructure map of the current team. Every team member
 * can view it; it changes nothing. The JSON is cached per team (see
 * TopologyCacheInvalidator for the cache policy) and answers 304 when
 * the client already has the same version.
 */
class NexusController extends Controller
{
    public function index(TopologyBuilder $topology): View
    {
        // The page renders the summary itself, so it is useful before (and
        // without) JavaScript; the map loads the same data from graph().
        return view('nexus.index', [
            'topology' => $topology->forTeam($this->teamId()),
        ]);
    }

    /**
     * Wall screen: a full-screen show of skyline, globe and heartbeat for a
     * TV, that cuts to an incident as soon as one opens. Also the screensaver.
     */
    public function wall(TopologyBuilder $topology, GlobeData $globe): View
    {
        $teamId = $this->teamId();

        return view('nexus.wall', [
            'topology' => $topology->forTeam($teamId),
            'globe' => $globe->forTeam($teamId),
            'team' => Team::query()->find($teamId)?->name,
        ]);
    }

    /** Time travel: a film of the last 24 hours or 7 days over the skyline. */
    public function timelapse(TimelapseData $timelapse): View
    {
        return view('nexus.timelapse', [
            'timelapse' => $timelapse->forTeam($this->teamId(), '24h'),
            'team' => Team::query()->find($this->teamId())?->name,
        ]);
    }

    public function timelapseData(Request $request, TimelapseData $timelapse): JsonResponse
    {
        $range = (string) $request->query('range', '24h');

        return response()->json(['data' => $timelapse->forTeam($this->teamId(), $range)])->setPrivate();
    }

    /** The servers as a city at night; the page polls graph() for updates. */
    public function skyline(TopologyBuilder $topology): View
    {
        return view('nexus.skyline', [
            'topology' => $topology->forTeam($this->teamId()),
        ]);
    }

    public function graph(Request $request, TopologyBuilder $topology): JsonResponse
    {
        $teamId = $this->teamId();
        $data = $topology->forTeam($teamId);

        $response = response()->json(['data' => $data]);

        // The ETag covers the content, not the moment it was built: a map
        // that did not change answers 304 even after the cache entry was
        // rebuilt. The team is part of it, so it never matches across teams.
        $content = $data;
        unset($content['meta']['generated_at']);
        $response->setEtag(hash('sha256', $teamId.'|'.json_encode($content)));
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-cache');
        $response->setVary(['Cookie'], false);
        $response->isNotModified($request);

        return $response;
    }

    /** The tenant middleware always sets a team; without one there is nothing to show. */
    private function teamId(): int
    {
        abort_unless(Tenant::check(), 403);

        return (int) Tenant::id();
    }
}
