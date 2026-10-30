<x-layouts.guest :title="__('auth.forgot.title')">
    <h1 class="m-0 text-2xl font-semibold tracking-tight">{{ __('auth.forgot.title') }}</h1>
    <p class="mt-2 text-sm text-muted">{{ __('auth.forgot.intro') }}</p>

    @include('auth._messages')

    <form method="POST" action="{{ route('password.email') }}" class="mt-6 grid gap-4">
        @csrf
        <div>
            <label class="ss-label" for="email">{{ __('auth.fields.email') }}</label>
            <input id="email" class="ss-input mt-1.5" type="email" name="email" value="{{ old('email') }}" autocomplete="username" required autofocus>
        </div>
        <button type="submit" class="ss-btn ss-btn-primary w-full">{{ __('auth.forgot.submit') }}</button>
    </form>
    <div class="mt-5 text-sm"><a class="text-accent underline-offset-2 hover:underline" href="{{ route('login') }}">{{ __('auth.forgot.back') }}</a></div>
</x-layouts.guest>
