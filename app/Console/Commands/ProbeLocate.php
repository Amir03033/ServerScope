<?php

namespace App\Console\Commands;

use App\Models\Probe;
use Illuminate\Console\Command;

/** Places an existing probe on the NEXUS globe (or removes it with --clear). Changes nothing else. */
class ProbeLocate extends Command
{
    protected $signature = 'serverscope:probe:locate {name} {--lat= : Latitude, -90..90} {--lon= : Longitude, -180..180} {--location= : Optional new place name} {--clear : Remove the coordinates}';

    protected $description = 'Set the coordinates of a remote probe for the globe';

    public function handle(): int
    {
        $probe = Probe::query()->where('name', $this->argument('name'))->first();

        if ($probe === null) {
            $this->error('No probe with this name.');

            return self::FAILURE;
        }

        if ($this->option('clear')) {
            $probe->forceFill(['latitude' => null, 'longitude' => null])->save();
            $this->info("{$probe->name} is no longer placed on the globe.");

            return self::SUCCESS;
        }

        $place = Probe::coordinates($this->option('lat'), $this->option('lon'));

        if ($place === null) {
            $this->error('Give both --lat (-90..90) and --lon (-180..180).');

            return self::FAILURE;
        }

        $probe->forceFill(['latitude' => $place[0], 'longitude' => $place[1], ...($this->option('location') ? ['location' => $this->option('location')] : [])])->save();
        $this->info("{$probe->name} placed at {$place[0]}, {$place[1]}.");

        return self::SUCCESS;
    }
}
