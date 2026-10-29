<?php

namespace App\Services;

use App\Models\AgentRelease;

/**
 * Update requests for agents (dashboard → updater on the server).
 */
class AgentUpdates
{
    /** Open requests for a withdrawn release can no longer be served. */
    public function expireOpenRequestsFor(AgentRelease $release): int
    {
        return 0;
    }
}
