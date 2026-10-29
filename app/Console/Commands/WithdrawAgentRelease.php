<?php

namespace App\Console\Commands;

use App\Models\AgentRelease;
use App\Services\AgentUpdates;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * A withdrawn release is no longer offered to install.sh or the updater.
 * Servers that already run it keep running it (there are no downgrades).
 */
class WithdrawAgentRelease extends Command
{
    protected $signature = 'serverscope:release:withdraw {version} {--admin= : E-mail address of a platform administrator} {--reason= : Why (shown to platform administrators)}';

    protected $description = 'Withdraw an agent release';

    public function handle(AgentUpdates $updates): int
    {
        $admin = ImportAgentRelease::platformAdmin((string) $this->option('admin'));
        $reason = trim((string) $this->option('reason'));

        if ($admin === null || $reason === '') {
            $this->error('Geef --admin (e-mailadres van een platformbeheerder) en --reason op.');

            return self::FAILURE;
        }

        $release = AgentRelease::query()->where('version', (string) $this->argument('version'))->first();

        if ($release === null) {
            $this->error('Deze versie is niet geïmporteerd.');

            return self::FAILURE;
        }

        if ($release->withdrawn_at === null) {
            $release->forceFill(['withdrawn_at' => now(), 'withdrawn_by' => $admin->id, 'withdraw_reason' => mb_substr($reason, 0, 255)])->save();
            $expired = $updates->expireOpenRequestsFor($release);
            Log::info('agent_release.withdrawn', ['version' => $release->version, 'admin' => $admin->id, 'expired_requests' => $expired]);
            $this->info("Release {$release->version} is teruggetrokken; {$expired} openstaande update(s) vervallen.");
        } else {
            $this->info("Release {$release->version} was al teruggetrokken.");
        }

        return self::SUCCESS;
    }
}
