<?php

namespace App\Console\Commands;

use App\Services\AgentHeartbeatService;
use Illuminate\Console\Command;

class CheckAgentHeartbeats extends Command
{
    protected $signature = 'serverscope:check-agents';

    protected $description = 'Mark silent agents offline and evaluate agent offline alert rules';

    public function handle(AgentHeartbeatService $heartbeats): int
    {
        $result = $heartbeats->check();

        $this->info(sprintf(
            'Offline gemarkeerd: %d, alerts geactiveerd: %d, alerts opgelost: %d',
            $result['marked_offline'],
            $result['triggered'],
            $result['resolved'],
        ));

        return self::SUCCESS;
    }
}
