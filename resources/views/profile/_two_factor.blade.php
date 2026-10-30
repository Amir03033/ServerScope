@php $user = auth()->user(); @endphp

<section class="ss-panel" id="two-factor" aria-labelledby="two-factor-title">
    <div class="ss-panel-head">
        <div><h2 id="two-factor-title" class="ss-panel-title">{{ __('profile.two_factor.title') }}</h2><p class="ss-panel-sub">{{ __('profile.two_factor.text') }}</p></div>
        <x-status :status="$user->hasTwoFactorEnabled() ? 'enabled' : 'disabled'" />
    </div>

    @if (session('recovery_codes'))
        <div class="ss-alert ss-alert-warning mb-4" role="status">
            <svg class="ico"><use href="#i-key"/></svg>
            <div class="min-w-0 flex-1">
                <strong>{{ __('profile.two_factor.recovery_title') }}</strong>
                <p class="mt-1 mb-2.5 text-sm">{{ __('profile.two_factor.recovery_text') }}</p>
                <div class="grid grid-cols-2 gap-1.5 font-mono text-sm">
                    @foreach (session('recovery_codes') as $code)<span>{{ $code }}</span>@endforeach
                </div>
            </div>
        </div>
    @endif

    @if ($user->hasTwoFactorEnabled())
        <p class="mt-0 text-sm text-muted">{{ __('profile.two_factor.enabled_on', ['date' => \App\Support\Time::dateTime($user->two_factor_confirmed_at), 'count' => count($user->two_factor_recovery_codes ?? [])]) }}</p>
        <form method="POST" action="{{ route('two-factor.recovery-codes') }}" class="mt-3 flex flex-wrap gap-2">
            @csrf
            <input class="ss-input min-w-40 flex-1" type="password" name="current_password" placeholder="{{ __('profile.two_factor.current_password') }}" required autocomplete="current-password" aria-label="{{ __('profile.two_factor.current_password') }}">
            <button class="ss-btn ss-btn-secondary" type="submit">{{ __('profile.two_factor.new_codes') }}</button>
        </form>
        <form method="POST" action="{{ route('two-factor.show') }}" class="mt-3 flex flex-wrap gap-2" data-confirm="{{ __('profile.two_factor.disable_confirm') }}">
            @csrf @method('DELETE')
            <input class="ss-input min-w-40 flex-1" type="password" name="current_password" placeholder="{{ __('profile.two_factor.current_password') }}" required autocomplete="current-password" aria-label="{{ __('profile.two_factor.current_password') }}">
            <button class="ss-btn ss-btn-danger" type="submit">{{ __('profile.two_factor.disable') }}</button>
        </form>
    @elseif ($user->two_factor_secret)
        <ol class="ss-steps mb-4">
            <li>{{ __('profile.two_factor.step_open') }}</li>
            <li>{!! __('profile.two_factor.step_key', ['key' => '<code class="select-all font-mono">'.e(trim(chunk_split($user->two_factor_secret, 4, ' '))).'</code>']) !!}</li>
            <li>{!! __('profile.two_factor.step_link', ['link' => '<a class="text-accent" href="'.e(\App\Support\Totp::uri($user->two_factor_secret, $user->email)).'">'.e(__('profile.two_factor.this_link')).'</a>']) !!}</li>
            <li>{{ __('profile.two_factor.step_code') }}</li>
        </ol>
        <form method="POST" action="{{ route('two-factor.confirm') }}" class="flex flex-wrap gap-2">
            @csrf
            <input class="ss-input !w-36 font-mono" name="code" inputmode="numeric" autocomplete="one-time-code" placeholder="123456" required aria-label="{{ __('profile.two_factor.code') }}">
            <button class="ss-btn ss-btn-primary" type="submit">{{ __('profile.two_factor.confirm') }}</button>
        </form>
        @error('code', 'twoFactor')<p class="ss-error">{{ $message }}</p>@enderror
    @else
        <form method="POST" action="{{ route('two-factor.enable') }}">
            @csrf
            <button class="ss-btn ss-btn-primary" type="submit"><svg class="ico"><use href="#i-shield"/></svg> {{ __('profile.two_factor.setup') }}</button>
        </form>
    @endif
    @error('current_password', 'twoFactor')<p class="ss-error">{{ $message }}</p>@enderror
</section>
