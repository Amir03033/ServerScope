<?php

namespace App\Http\Controllers;

use App\Services\Notifications\NotificationDispatcher;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function edit(Request $request): View
    {
        return view('profile.edit', ['user' => $request->user()]);
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
        ]);

        $validated['email'] = strtolower(trim($validated['email']));
        $user->fill($validated);
        $changes = Audit::diff($user);
        $user->save();

        if ($changes !== []) {
            Audit::log('user.profile_updated', 'Profiel bijgewerkt', $user, ['changes' => $changes]);
        }

        return back()->with('success', 'Profiel opgeslagen.');
    }

    public function password(Request $request): RedirectResponse
    {
        $validated = $request->validateWithBag('password', [
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::min(12)->mixedCase()->numbers()],
        ]);

        $user = $request->user();
        $user->forceFill(['password' => Hash::make($validated['password'])])->save();

        // End other sessions that may still use the old password.
        Auth::logoutOtherDevices($validated['password']);

        Audit::log('user.password_changed', 'Wachtwoord gewijzigd', $user);

        return back()->with('success', 'Wachtwoord gewijzigd. Andere sessies zijn afgemeld.');
    }

    public function notifications(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'notify_email' => ['boolean'],
            'notify_in_app' => ['boolean'],
            'notify_resolved' => ['boolean'],
            'notify_dns_changes' => ['boolean'],
            'notify_min_severity' => ['required', Rule::in(['info', 'warning', 'critical'])],
        ]);

        $request->user()->forceFill([
            'notify_email' => $request->boolean('notify_email'),
            'notify_in_app' => $request->boolean('notify_in_app'),
            'notify_resolved' => $request->boolean('notify_resolved'),
            'notify_dns_changes' => $request->boolean('notify_dns_changes'),
            'notify_min_severity' => $validated['notify_min_severity'],
        ])->save();

        return back()->with('success', 'Meldingsvoorkeuren opgeslagen.');
    }

    public function testNotification(Request $request, NotificationDispatcher $dispatcher): RedirectResponse
    {
        $key = 'test-notification:'.$request->user()->id;

        if (RateLimiter::tooManyAttempts($key, 3)) {
            return back()->with('error', 'Te veel testmeldingen; probeer het over een paar minuten opnieuw.');
        }

        RateLimiter::hit($key, 600);
        $dispatcher->test($request->user());

        return back()->with('success', 'Testmelding verstuurd. Bekijk de verzendstatus onder Meldingen.');
    }
}
