<?php

namespace App\Console\Commands;

use App\Models\Team;
use App\Support\PlanLimits;
use Illuminate\Console\Command;

/** Operator tool until payments exist: set a team's plan by hand. */
class SetTeamPlan extends Command
{
    protected $signature = 'serverscope:team:plan {team : Team id or slug} {plan : free|pro|premium} {--pending : Mark as pending_payment}';

    protected $description = 'Set the plan of a team (manual activation, e.g. invoice or pilot customer)';

    public function handle(): int
    {
        $team = Team::query()->where('id', $this->argument('team'))->orWhere('slug', $this->argument('team'))->first();
        $plan = strtolower((string) $this->argument('plan'));

        if ($team === null || ! in_array($plan, PlanLimits::PLANS, true)) {
            $this->error('Unknown team or plan (free, pro, premium).');

            return self::FAILURE;
        }

        $team->forceFill([
            'plan' => $plan,
            'plan_status' => ($plan === 'free' || ! $this->option('pending')) ? PlanLimits::ACTIVE : PlanLimits::PENDING_PAYMENT,
        ])->save();

        $this->info("Team {$team->name}: {$team->plan} ({$team->plan_status})");

        return self::SUCCESS;
    }
}
