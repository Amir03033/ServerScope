<?php

namespace Database\Factories;

use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Users made by the factory join the default team unless a test puts
     * them in a team itself, so tests written before teams existed keep
     * their meaning: 'is_admin' => true makes an administrator of that
     * team (the role is the source; the column is its mirror).
     */
    public function configure(): static
    {
        return $this->afterCreating(function (User $user): void {
            $team = Team::defaultTeam();

            if ($team === null || $user->teams()->exists()) {
                return;
            }

            $user->teams()->attach($team->id, ['role' => $user->is_admin ? 'admin' : 'member']);
            $user->forceFill(['current_team_id' => $team->id])->saveQuietly();
        });
    }

    /** Administrator of the default team. */
    public function admin(): static
    {
        return $this->state(['is_admin' => true]);
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
