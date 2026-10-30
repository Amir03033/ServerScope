<x-layouts.guest :title="__('auth.reset.title')">
    <h1 class="m-0 text-2xl font-semibold tracking-tight">{{ __('auth.reset.title') }}</h1>
    <p class="mt-2 text-sm text-muted">{{ __('auth.reset.intro') }}</p>

    @include('auth._messages')

    <form method="POST" action="{{ route('password.update') }}" class="mt-6 grid gap-4">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <div>
            <label class="ss-label" for="email">{{ __('auth.fields.email') }}</label>
            <input id="email" class="ss-input mt-1.5" type="email" name="email" value="{{ old('email', $email) }}" autocomplete="username" required>
        </div>
        <div>
            <label class="ss-label" for="password">{{ __('auth.fields.new_password') }}</label>
            <input id="password" class="ss-input mt-1.5" type="password" name="password" autocomplete="new-password" required autofocus>
        </div>
        <div>
            <label class="ss-label" for="password_confirmation">{{ __('auth.fields.password_confirmation') }}</label>
            <input id="password_confirmation" class="ss-input mt-1.5" type="password" name="password_confirmation" autocomplete="new-password" required>
        </div>
        <button type="submit" class="ss-btn ss-btn-primary w-full">{{ __('auth.reset.submit') }}</button>
    </form>
</x-layouts.guest>
