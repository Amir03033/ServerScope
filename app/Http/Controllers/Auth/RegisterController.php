<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Models\User;
use App\Notifications\RegistrationAttemptNotice;
use App\Support\Audit;
use App\Support\PlanLimits;
use App\Tenancy\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * Public sign-up: a new account that owns a new, empty team. Disabled
 * unless serverscope.tenancy.registration_enabled is set; the routes
 * then do not exist (404).
 */
class RegisterController extends Controller
{
    public function create(Request $request): View
    {
        $this->ensureEnabled();

        return view('auth.register', ['plan' => PlanLimits::normalize($request->query('plan'))]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->ensureEnabled();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'team' => ['required', 'string', 'max:100'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'confirmed', Password::min(12)->mixedCase()->numbers()],
        ]);

        $email = strtolower(trim($validated['email']));

        // The answer is the same for a new and an existing address, so this
        // form does not reveal which addresses have an account. An existing
        // address gets a notice by mail instead.
        $existing = User::query()->where('email', $email)->first();

        if ($existing !== null) {
            $existing->notify((new RegistrationAttemptNotice)->locale($existing->preferredLocale() ?? app()->getLocale()));

            return redirect()->route('register.sent');
        }

        // The submitted plan is only a wish: it is whitelisted here and
        // grants nothing. Paid plans stay pending (Free limits) until a
        // payment confirms them.
        $plan = PlanLimits::normalize($request->input('plan'));
        $planStatus = $plan === PlanLimits::FREE ? PlanLimits::ACTIVE : PlanLimits::PENDING_PAYMENT;

        $user = DB::transaction(function () use ($validated, $email, $plan, $planStatus): User {
            $team = Team::query()->create(['name' => $validated['team'], 'plan' => $plan, 'plan_status' => $planStatus]);

            $user = new User;
            $user->name = $validated['name'];
            $user->email = $email;
            $user->password = Hash::make($validated['password']);
            $user->is_admin = true;
            $user->current_team_id = $team->id;
            $user->locale = app()->getLocale();
            $user->save();
            $user->teams()->attach($team->id, ['role' => 'owner']);

            Tenant::run($team, fn () => Audit::log('team.registered', "Team {$team->name} geregistreerd door {$user->email} (plan {$team->plan}, {$team->plan_status})", $user));

            return $user;
        });

        // Nothing works until the address is verified: no sign-in here.
        $user->sendEmailVerificationNotification();

        return redirect()->route('register.sent');
    }

    public function sent(): View
    {
        $this->ensureEnabled();

        return view('auth.register-sent');
    }

    private function ensureEnabled(): void
    {
        abort_unless((bool) config('serverscope.tenancy.registration_enabled'), 404);
    }
}
