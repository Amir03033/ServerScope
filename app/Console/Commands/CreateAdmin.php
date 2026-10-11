<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class CreateAdmin extends Command
{
    protected $signature = 'serverscope:create-admin';

    protected $description = 'Create or promote a ServerScope administrator';

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
        $user->save();

        $this->info("Beheerder aangemaakt of bijgewerkt: {$user->email}");

        return self::SUCCESS;
    }
}
