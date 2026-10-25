<?php

namespace App\Console\Commands;

use App\Jobs\RunDomainCheck;
use App\Models\Domain;
use App\Services\Domains\DomainCheckRunner;
use Illuminate\Console\Command;

/**
 * Dispatches due domain checks. Each check is claimed by atomically
 * moving its next_check_at forward before dispatch, so concurrent or
 * overlapping scheduler runs never queue the same check twice.
 */
class DispatchDomainChecks extends Command
{
    protected $signature = 'serverscope:dispatch-domain-checks {--limit=500 : Maximum checks per type per run}';

    protected $description = 'Queue HTTP, TLS, DNS and expiry checks that are due';

    public function handle(): int
    {
        $now = now();
        $dispatched = array_fill_keys(DomainCheckRunner::TYPES, 0);

        foreach (DomainCheckRunner::TYPES as $type) {
            $column = "{$type}_next_check_at";

            $domains = Domain::query()
                ->monitored()
                ->where("{$type}_enabled", true)
                ->when($type === 'ssl', fn ($q) => $q->where('scheme', 'https'))
                ->where(fn ($q) => $q->whereNull($column)->orWhere($column, '<=', $now))
                ->orderByRaw("{$column} IS NOT NULL")
                ->orderBy($column)
                ->limit((int) $this->option('limit'))
                ->get();

            foreach ($domains as $domain) {
                $claimed = Domain::query()
                    ->whereKey($domain->id)
                    ->where(fn ($q) => $q->whereNull($column)->orWhere($column, '<=', $now))
                    ->update([$column => $now->copy()->addMinutes(DomainCheckRunner::interval($domain, $type))]);

                if ($claimed === 1) {
                    RunDomainCheck::dispatch($domain->id, $type);
                    $dispatched[$type]++;
                }
            }
        }

        $this->info(collect($dispatched)->map(fn ($n, $t) => "{$t}: {$n}")->implode(', '));

        return self::SUCCESS;
    }
}
