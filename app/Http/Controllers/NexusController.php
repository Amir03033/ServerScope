<?php

namespace App\Http\Controllers;

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
