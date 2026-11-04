<?php

namespace App\Console\Commands;

use App\Services\Demo\DemoSeeder;
use App\Support\Demo;
use Illuminate\Console\Command;

class DemoSeed extends Command
{
    protected $signature = 'serverscope:demo:seed {--locale=* : Only these languages (en, nl); default both}';

    protected $description = 'Build or rebuild the read-only demo teams with sample data relative to now';

    public function handle(DemoSeeder $seeder): int
    {
        $locales = array_values(array_intersect($this->option('locale') ?: Demo::LOCALES, Demo::LOCALES));

        foreach ($locales as $locale) {
            $team = $seeder->seed($locale);
            $this->info("Demo {$locale}: team {$team->id} ({$team->slug}) opgebouwd.");
        }

        if (! Demo::enabled()) {
            $this->warn('SERVERSCOPE_DEMO_ENABLED staat uit: /demo geeft 404 tot je hem aanzet.');
        }

        return self::SUCCESS;
    }
}
