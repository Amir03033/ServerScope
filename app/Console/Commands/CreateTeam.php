<?php

namespace App\Console\Commands;

use App\Models\Team;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Creates a team (customer) with an existing account as owner. While
 * public registration is off, this is how new customers are added.
 */
class CreateTeam extends Command
{
    protected $signature = 'serverscope:create-team {name : Name of the team} {--owner= : E-mail address of an existing account that becomes owner}';

    protected $description = 'Create a team with an existing user as owner';

    public function handle(): int
    {
        $email = strtolower(trim((string) $this->option('owner')));
        $owner = User::query()->where('email', $email)->first();

        if ($owner === null) {
            $this->error('Geef met --owner het e-mailadres van een bestaand account op.');

            return self::FAILURE;
        }

        $team = DB::transaction(function () use ($owner): Team {
            $team = Team::query()->create(['name' => (string) $this->argument('name')]);
            $owner->teams()->attach($team->id, ['role' => 'owner']);

            return $team;
        });

        $this->info("Team '{$team->name}' aangemaakt (slug {$team->slug}) met {$owner->email} als eigenaar. De gebruiker kan via het teammenu naar dit team wisselen.");

        return self::SUCCESS;
    }
}
