<x-layouts.guest :title="__('auth.two_factor.title')">
    {{-- A shield that breathes; the code boxes are drawn by app.js over the plain field (works without script). --}}
    <div class="ss-tfa-shield" aria-hidden="true">
        <i></i><i></i><i></i>
        <svg viewBox="0 0 64 64">
            <path class="body" d="M32 6 52 14v16c0 13-8.5 23-20 28C20.5 53 12 43 12 30V14Z"/>
            <path class="lock" d="M25 31v-4a7 7 0 0 1 14 0v4M23 31h18v12H23Z"/>
            <path class="tick" d="M24 34l6 6 11-12"/>
        </svg>
    </div>

    <h1 class="ss-auth-title text-center">{{ __('auth.two_factor.heading') }}</h1>
    <p class="ss-auth-intro text-center">{{ __('auth.two_factor.intro') }}</p>

    @include('auth._messages')

    <form method="POST" action="{{ route('two-factor.challenge.store') }}" class="ss-auth-form" data-auth-form data-auth-ghost data-tfa @if ($errors->any()) data-tfa-error @endif>
        @csrf
        <div class="ss-auth-field">
            <label class="ss-label" for="code">{{ __('auth.two_factor.code') }}</label>
            <input id="code" class="ss-input mt-1.5 font-mono tracking-widest" name="code" inputmode="numeric" pattern="[0-9 ]*" maxlength="7" autocomplete="one-time-code" autofocus
                   data-tfa-code data-digit-label="{{ __('auth.two_factor.digit', ['n' => '__N__']) }}">
            <p class="ss-tfa-timer" data-tfa-timer data-label="{{ __('auth.two_factor.refresh', ['seconds' => '__S__']) }}" hidden>
                <svg viewBox="0 0 20 20" aria-hidden="true"><circle cx="10" cy="10" r="8"/><circle class="run" cx="10" cy="10" r="8"/></svg>
                <span></span>
            </p>
        </div>

        <details class="ss-tfa-recovery ss-auth-field" @if (old('recovery_code')) open @endif>
            <summary>{{ __('auth.two_factor.recovery_toggle') }}</summary>
            <label class="ss-label mt-3" for="recovery_code">{{ __('auth.two_factor.recovery_code') }}</label>
            <input id="recovery_code" class="ss-input mt-1.5 font-mono" name="recovery_code" autocomplete="off" spellcheck="false">
        </details>

        <button type="submit" class="ss-btn ss-btn-primary ss-auth-submit"><span>{{ __('auth.two_factor.submit') }}</span></button>
    </form>

    <form method="POST" action="{{ route('two-factor.challenge.cancel') }}" class="ss-auth-alt">
        @csrf
        <button type="submit" class="ss-auth-link ss-tfa-back"><svg class="ico" aria-hidden="true"><use href="#i-arrow-left"/></svg>{{ __('auth.two_factor.back') }}</button>
    </form>

    <p class="ss-tfa-secure"><svg class="ico" aria-hidden="true"><use href="#i-lock"/></svg>{{ __('auth.two_factor.secure') }}</p>
</x-layouts.guest>
