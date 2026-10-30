<x-layouts.guest :title="__('auth.two_factor.title')">
    <h1 class="m-0 text-2xl font-semibold tracking-tight">{{ __('auth.two_factor.title') }}</h1>
    <p class="mt-2 text-sm text-muted">{{ __('auth.two_factor.intro') }}</p>

    @include('auth._messages')

    <form method="POST" action="{{ route('two-factor.challenge.store') }}" class="mt-6 grid gap-4">
        @csrf
        <div>
            <label class="ss-label" for="code">{{ __('auth.two_factor.code') }}</label>
            <input id="code" class="ss-input mt-1.5 font-mono tracking-widest" name="code" inputmode="numeric" pattern="[0-9 ]*" autocomplete="one-time-code" autofocus>
        </div>
        <div>
            <label class="ss-label" for="recovery_code">{{ __('auth.two_factor.recovery_code') }}</label>
            <input id="recovery_code" class="ss-input mt-1.5 font-mono" name="recovery_code" autocomplete="off">
        </div>
        <button type="submit" class="ss-btn ss-btn-primary w-full">{{ __('auth.two_factor.submit') }}</button>
    </form>
    <form method="POST" action="{{ route('two-factor.challenge.cancel') }}" class="mt-3">
        @csrf
        <button type="submit" class="ss-btn ss-btn-secondary w-full">{{ __('common.cancel') }}</button>
    </form>
</x-layouts.guest>
