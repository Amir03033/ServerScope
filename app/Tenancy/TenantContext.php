<?php

namespace App\Tenancy;

/**
 * The team the current request acts for. Only HTTP middleware sets it
 * (signed-in user, API token or agent credential); scheduled commands
 * and queued jobs always run without a tenant and must derive the team
 * from the records they process.
 */
class TenantContext
{
    private ?int $teamId = null;

    public function id(): ?int
    {
        return $this->teamId;
    }

    public function set(?int $teamId): void
    {
        $this->teamId = $teamId;
    }
}
