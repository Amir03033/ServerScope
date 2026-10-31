<x-layouts.guest :title="__('invitations.title')">
    <h1 class="m-0 text-2xl font-semibold tracking-tight">{{ __('invitations.title') }}</h1>
    <p class="mt-2 text-sm text-muted">{{ __('invitations.intro', ['team' => $invitation->team->name, 'email' => $invitation->email]) }}</p>

    @include('auth._messages')

    @if ($matches)
        <form method="POST" action="{{ route('invitations.accept', $token) }}" class="mt-6">
            @csrf
            <button type="submit" class="ss-btn ss-btn-primary w-full">{{ __('invitations.accept') }}</button>
        </form>
    @elseif ($signedInAs)
        <p class="mt-4 text-sm">{{ __('invitations.wrong_account', ['email' => $invitation->email]) }}</p>
        <form method="POST" action="{{ route('logout') }}" class="mt-3">@csrf<button type="submit" class="ss-btn w-full">{{ __('auth.verify.logout') }}</button></form>
    @elseif ($hasAccount)
        <p class="mt-4 text-sm">{{ __('invitations.login_first') }}</p>
        <a class="ss-btn ss-btn-primary mt-3 w-full" href="{{ route('invitations.login', $token) }}">{{ __('auth.login.title') }}</a>
    @else
        <form method="POST" action="{{ route('invitations.register', $token) }}" class="mt-6 grid gap-4">
            @csrf
            <div>
                <label class="ss-label" for="name">{{ __('auth.fields.name') }}</label>
                <input id="name" class="ss-input mt-1.5" type="text" name="name" value="{{ old('name', $invitation->name) }}" autocomplete="name" required>
            </div>
            <div>
                <label class="ss-label" for="password">{{ __('auth.fields.password') }}</label>
                <input id="password" class="ss-input mt-1.5" type="password" name="password" autocomplete="new-password" required>
            </div>
            <div>
                <label class="ss-label" for="password_confirmation">{{ __('auth.fields.password_confirmation') }}</label>
                <input id="password_confirmation" class="ss-input mt-1.5" type="password" name="password_confirmation" autocomplete="new-password" required>
            </div>
            <button type="submit" class="ss-btn ss-btn-primary w-full">{{ __('invitations.create') }}</button>
        </form>
    @endif
</x-layouts.guest>
