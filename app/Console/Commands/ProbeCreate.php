<?php

namespace App\Console\Commands;

use App\Models\Probe;
use Illuminate\Console\Command;

/** Registers the remote probe on the central ServerScope and prints its credentials once. */
class ProbeCreate extends Command
{
    protected $signature = 'serverscope:probe:create {name} {--location= : e.g. "Frankfurt"} {--rotate : Replace the secret of an existing probe with this name}';

    protected $description = 'Register (or rotate) the remote confirmation probe';

    public function handle(): int
    {
        $probe = Probe::query()->where('name', $this->argument('name'))->first();

        if ($probe !== null && ! $this->option('rotate')) {
            $this->error('A probe with this name exists; use --rotate to replace its secret.');

            return self::FAILURE;
        }

        if ($probe === null && Probe::query()->exists()) {
            $this->error('Global Pulse Lite supports one probe; rotate the existing one instead.');

            return self::FAILURE;
        }

        $secret = bin2hex(random_bytes(32));
        $probe ??= new Probe(['name' => $this->argument('name')]);
        $probe->forceFill(['key' => $probe->key ?? bin2hex(random_bytes(16)), 'secret' => $secret, 'location' => $this->option('location') ?? $probe->location, 'is_active' => true])->save();

        $this->info('Set these on the probe host (shown once):');
        $this->line('SERVERSCOPE_PROBE_URL='.config('app.url'));
        $this->line("SERVERSCOPE_PROBE_KEY={$probe->key}");
        $this->line("SERVERSCOPE_PROBE_SECRET={$secret}");

        return self::SUCCESS;
    }
}
