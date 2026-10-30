<x-layouts.guest :title="__('auth.register.title')">
    <h1 class="m-0 text-2xl font-semibold tracking-tight">{{ __('auth.register.title') }}</h1>
    <p class="mt-2 text-sm text-muted">{{ __('auth.register.intro') }}</p>

    @include('auth._messages')

    <form method="POST" action="{{ route('register') }}" class="mt-6 grid gap-4">
        @csrf
        <div>
            <label class="ss-label" for="name">{{ __('auth.fields.name') }}</label>
            <input id="name" class="ss-input mt-1.5" type="text" name="name" value="{{ old('name') }}" autocomplete="name" required autofocus>
        </div>
        <div>
            <label class="ss-label" for="team">{{ __('auth.fields.team') }}</label>
            <input id="team" class="ss-input mt-1.5" type="text" name="team" value="{{ old('team') }}" autocomplete="organization" required>
        </div>
        <div>
            <label class="ss-label" for="email">{{ __('auth.fields.email') }}</label>
            <input id="email" class="ss-input mt-1.5" type="email" name="email" value="{{ old('email') }}" autocomplete="username" required>
        </div>
        <div>
            <label class="ss-label" for="password">{{ __('auth.fields.password') }}</label>
            <input id="password" class="ss-input mt-1.5" type="password" name="password" autocomplete="new-password" required>
        </div>
        <div>
            <label class="ss-label" for="password_confirmation">{{ __('auth.fields.password_confirmation') }}</label>
            <input id="password_confirmation" class="ss-input mt-1.5" type="password" name="password_confirmation" autocomplete="new-password" required>
        </div>
        <button type="submit" class="ss-btn ss-btn-primary w-full">{{ __('auth.register.title') }}</button>
    </form>
    <div class="mt-5 text-sm"><a class="text-accent underline-offset-2 hover:underline" href="{{ route('login') }}">{{ __('auth.register.have_account') }}</a></div>
</x-layouts.guest>
