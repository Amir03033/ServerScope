<?php

namespace App\Rules;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A user id of a member of the given team. Users of other teams get the
 * same message as ids that do not exist, so membership does not leak.
 */
class TeamMember implements ValidationRule
{
    public function __construct(private ?int $teamId) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $isMember = $this->teamId !== null
            && filter_var($value, FILTER_VALIDATE_INT) !== false
            && User::query()->whereKey((int) $value)->whereHas('teams', fn ($q) => $q->where('teams.id', $this->teamId))->exists();

        if (! $isMember) {
            $fail('validation.exists')->translate();
        }
    }

    /** Members of a team, for pickers. */
    public static function options(?int $teamId)
    {
        return User::query()->whereHas('teams', fn ($q) => $q->where('teams.id', $teamId))->orderBy('name')->get(['id', 'name']);
    }
}
