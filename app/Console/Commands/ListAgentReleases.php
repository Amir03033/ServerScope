<?php

namespace App\Console\Commands;

use App\Models\AgentRelease;
use Illuminate\Console\Command;

class ListAgentReleases extends Command
{
    protected $signature = 'serverscope:release:list';

    protected $description = 'List imported agent releases';

    public function handle(): int
    {
        $this->table(['Versie', 'Datum', 'Sleutel-ID', 'Commit', 'SHA-256', 'Status'], AgentRelease::query()->newestFirst()->get()->map(fn (AgentRelease $release) => [
            $release->version,
            $release->released_at->toDateString(),
            $release->key_id,
            substr($release->commit, 0, 12),
            substr($release->sha256, 0, 16).'…',
            $release->withdrawn_at ? 'teruggetrokken ('.$release->withdraw_reason.')' : 'beschikbaar',
        ])->all());

        return self::SUCCESS;
    }
}
