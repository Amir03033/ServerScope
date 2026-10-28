<?php

namespace App\Console\Commands;

use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class CreateAdmin extends Command
{
    protected $signature = 'serverscope:create-admin {--platform : Also make the account platform administrator (platform-wide system status)}';

    protected $description = 'Create or promote an administrator of the default team';

    public function handle(): int
    {
        $name = $this->ask('Naam');
        $email = strtolower(trim($this->ask('E-mailadres')));
        $password = $this->secret('Wachtwoord');
        $confirmation = $this->secret('Herhaal wachtwoord');

        $validator = Validator::make([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'password_confirmation' => $confirmation,
        ], [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'confirmed', Password::min(12)->mixedCase()->numbers()],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $existingUser = User::where('email', $email)->first();

        if ($existingUser && ! $this->confirm(
                'Dit account bestaat al. Maak je dit account beheerder door het wachtwoord te wijzigen?',
                false
            )) {
            $this->info('Geen wijzigingen uitgevoerd.');

            return self::SUCCESS;
        }

        $user = $existingUser ?? new User();

        $user->name = $name;
        $user->email = $email;
        $user->password = Hash::make($password);
        $user->is_admin = true;
        $user->email_verified_at ??= now();

        if ($this->option('platform')) {
            $user->is_platform_admin = true;
        }

        // Administrator (or owner, when the team has none) of the default team.
        $team = Team::defaultTeam();

        if ($team === null) {
            $this->error('Het standaardteam ontbreekt; draai eerst de migraties.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($user, $team): void {
            $user->current_team_id ??= $team->id;
            $user->save();

            $role = $team->users()->wherePivot('role', 'owner')->exists() ? 'admin' : 'owner';
            $current = $user->roleIn($team);

            if ($current === null) {
                $user->teams()->attach($team->id, ['role' => $role]);
            } elseif ($current === 'member') {
                $user->teams()->updateExistingPivot($team->id, ['role' => $role]);
            }

            $user->syncAdminMirror(quietly: true);
        });

        $this->info("Beheerder aangemaakt of bijgewerkt: {$user->email}".($user->is_platform_admin ? ' (platformbeheerder)' : ''));

        return self::SUCCESS;
    }
}
