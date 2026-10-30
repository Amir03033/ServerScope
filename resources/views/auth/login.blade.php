<x-layouts.guest :title="__('auth.login.title')">
    <h1 class="m-0 text-2xl font-semibold tracking-tight">{{ __('auth.login.heading') }}</h1>
    <p class="mt-2 text-sm text-muted">{{ __('auth.login.intro') }}</p>

    @include('auth._messages')

    <form method="POST" action="{{ route('login') }}" class="mt-6 grid gap-4">
        @csrf

        <div>
            <label class="ss-label" for="email">{{ __('auth.fields.email') }}</label>
            <input id="email" class="ss-input mt-1.5" type="email" name="email" value="{{ old('email') }}" autocomplete="username" required autofocus
                   @error('email') aria-invalid="true" @enderror>
        </div>

        <div>
            <label class="ss-label" for="password">{{ __('auth.fields.password') }}</label>
            <input id="password" class="ss-input mt-1.5" type="password" name="password" autocomplete="current-password" required>
        </div>

        <label class="ss-check">
            <input type="checkbox" name="remember" value="1">
            {{ __('auth.login.remember') }}
        </label>

        <button type="submit" class="ss-btn ss-btn-primary w-full">{{ __('auth.login.submit') }}</button>
    </form>

    <div class="mt-5 text-sm"><a class="text-accent underline-offset-2 hover:underline" href="{{ route('password.request') }}">{{ __('auth.login.forgot') }}</a></div>
</x-layouts.guest>
