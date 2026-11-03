@php
    $field = fn (string $name, string $bag = 'default') => $errors->getBag($bag)->has($name) ? 'aria-invalid=true aria-describedby='.$name.'-error' : '';
@endphp

<x-layouts.app :title="__('profile.title')">
    <x-slot:subtitle>{{ __('profile.subtitle') }}</x-slot:subtitle>

    <div class="grid gap-4 lg:grid-cols-2 lg:items-start">
        <div class="grid min-w-0 gap-4">
            <section class="ss-panel" aria-labelledby="account-title">
                <h2 id="account-title" class="ss-panel-title mb-4">{{ __('profile.account') }}</h2>
                <form method="POST" action="{{ route('profile.update') }}">
                    @csrf @method('PUT')
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label class="ss-label" for="name">{{ __('profile.name') }}</label>
                            <input id="name" class="ss-input mt-1.5" name="name" type="text" required maxlength="255" value="{{ old('name', $user->name) }}" autocomplete="name" {{ $field('name') }}>
                            @error('name')<p id="name-error" class="ss-error">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label class="ss-label" for="email">{{ __('profile.email') }}</label>
                            <input id="email" class="ss-input mt-1.5" name="email" type="email" required maxlength="255" value="{{ old('email', $user->email) }}" autocomplete="email" {{ $field('email') }}>
                            @error('email')<p id="email-error" class="ss-error">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label class="ss-label" for="locale">{{ __('profile.locale.label') }}</label>
                            <select id="locale" class="ss-input mt-1.5" name="locale" {{ $field('locale') }}>
                                @foreach (\App\Support\Locale::SUPPORTED as $code)
                                    <option value="{{ $code }}" lang="{{ $code }}" @selected(old('locale', $user->locale ?? \App\Support\Locale::DEFAULT) === $code)>{{ __('locale.names.'.$code) }}</option>
                                @endforeach
                            </select>
                            <p class="ss-help">{{ __('profile.locale.help') }}</p>
                            @error('locale')<p id="locale-error" class="ss-error">{{ $message }}</p>@enderror
                        </div>
                    </div>
                    <div class="mt-5"><button class="ss-btn ss-btn-primary" type="submit">{{ __('profile.save') }}</button></div>
                </form>
            </section>

            <section class="ss-panel" aria-labelledby="password-title">
                <h2 id="password-title" class="ss-panel-title">{{ __('profile.password_title') }}</h2>
                <p class="ss-panel-sub mb-4">{{ __('profile.password_text') }}</p>
                <form method="POST" action="{{ route('profile.password') }}">
                    @csrf @method('PUT')
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="sm:col-span-2">
                            <label class="ss-label" for="current_password">{{ __('profile.current_password') }}</label>
                            <input id="current_password" class="ss-input mt-1.5" name="current_password" type="password" required autocomplete="current-password" {{ $field('current_password', 'password') }}>
                            @error('current_password', 'password')<p id="current_password-error" class="ss-error">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label class="ss-label" for="password">{{ __('profile.new_password') }}</label>
                            <input id="password" class="ss-input mt-1.5" name="password" type="password" required autocomplete="new-password" {{ $field('password', 'password') }}>
                            @error('password', 'password')<p id="password-error" class="ss-error">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label class="ss-label" for="password_confirmation">{{ __('profile.confirm_password') }}</label>
                            <input id="password_confirmation" class="ss-input mt-1.5" name="password_confirmation" type="password" required autocomplete="new-password">
                        </div>
                    </div>
                    <div class="mt-5"><button class="ss-btn ss-btn-secondary" type="submit">{{ __('profile.change_password') }}</button></div>
                </form>
            </section>

            @if (Route::has('two-factor.show'))
                @include('profile._two_factor')
            @endif

            @if (Route::has('api-tokens.index'))
                <section class="ss-panel" aria-labelledby="api-title">
                    <div class="ss-panel-head !mb-0"><div><h2 id="api-title" class="ss-panel-title">{{ __('profile.api_title') }}</h2><p class="ss-panel-sub">{{ __('profile.api_text') }}</p></div><a class="ss-btn ss-btn-secondary ss-btn-sm" href="{{ route('api-tokens.index') }}"><svg class="ico"><use href="#i-key"/></svg> {{ __('profile.manage') }}</a></div>
                </section>
            @endif
        </div>

        {{-- Appearance: light/dark by default; extra themes only when chosen here. --}}
        <section class="ss-panel" aria-labelledby="appearance-title">
            <h2 id="appearance-title" class="ss-panel-title">{{ __('themes.title') }}</h2>
            <p class="ss-panel-sub mb-4">{{ __('themes.text') }}</p>
            <noscript><p class="m-0 text-sm text-muted">{{ __('themes.noscript') }}</p></noscript>
            <fieldset class="m-0 border-0 p-0" data-theme-picker>
                <legend class="sr-only">{{ __('themes.title') }}</legend>
                @foreach (['default' => ['system', 'light', 'dark'], 'extra' => ['midnight', 'terminal', 'contrast']] as $group => $choices)
                    <h3 class="mb-2 mt-1 text-xs font-semibold uppercase tracking-wide text-muted {{ $group === 'extra' ? 'mt-5' : '' }}">{{ __('themes.'.$group) }}</h3>
                    <div class="ss-theme-grid">
                        @foreach ($choices as $choice)
                            <label class="ss-theme-card">
                                <input type="radio" name="ss-theme-choice" value="{{ $choice }}" data-theme-choice @checked($choice === 'system')>
                                <span class="ss-theme-swatch is-{{ $choice }}" aria-hidden="true"><i></i><i></i><i></i><i></i></span>
                                <span class="ss-theme-name">{{ __('themes.names.'.$choice) }}</span>
                                <span class="ss-theme-desc">{{ __('themes.descriptions.'.$choice) }}</span>
                            </label>
                        @endforeach
                    </div>
                @endforeach
            </fieldset>
        </section>

        <section class="ss-panel" aria-labelledby="notifications-title">
            <h2 id="notifications-title" class="ss-panel-title">{{ __('profile.notifications_title') }}</h2>
            <p class="ss-panel-sub mb-4">{{ __('profile.notifications_text') }}</p>
            <form method="POST" action="{{ route('profile.notifications') }}">
                @csrf @method('PUT')
                <div class="grid gap-4">
                    @foreach (['notify_email', 'notify_in_app', 'notify_resolved', 'notify_dns_changes'] as $key)
                        <label class="ss-check items-start">
                            <input type="hidden" name="{{ $key }}" value="0">
                            <input class="mt-0.5" type="checkbox" name="{{ $key }}" value="1" @checked(old($key, $user->{$key}))>
                            <span>
                                <strong>{{ __('profile.notify.'.$key) }}</strong>
                                <span class="ss-help block !mt-0.5">{{ $key === 'notify_email' ? __('profile.notify.notify_email_help', ['email' => $user->email]).(config('serverscope.alert_mail_enabled') ? '' : __('profile.notify.mail_disabled')) : __('profile.notify.'.$key.'_help') }}</span>
                            </span>
                        </label>
                    @endforeach
                    <div>
                        <label class="ss-label" for="notify_min_severity">{{ __('profile.min_severity') }}</label>
                        <select id="notify_min_severity" class="ss-input mt-1.5" name="notify_min_severity">
                            @foreach (['info', 'warning', 'high', 'critical'] as $v)
                                <option value="{{ $v }}" @selected(old('notify_min_severity', $user->notify_min_severity) === $v)>{{ __('profile.min_severities.'.$v) }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="mt-5"><button class="ss-btn ss-btn-primary" type="submit">{{ __('profile.save_preferences') }}</button></div>
            </form>
            <form method="POST" action="{{ route('profile.notifications.test') }}" class="mt-3">
                @csrf
                <button class="ss-btn ss-btn-ghost ss-btn-sm" type="submit"><svg class="ico"><use href="#i-bell"/></svg> {{ __('profile.send_test') }}</button>
            </form>

            {{-- Push on this device: an extra channel next to e-mail and in-app (same preferences). --}}
            <div class="ss-push-box" data-push data-push-state="unknown"
                 data-subscribe-url="{{ route('push.subscribe') }}"
                 data-public-key="{{ \App\Services\Push\PushSender::enabled() ? config('serverscope.push.public_key') : '' }}"
                 data-texts='@json(__('push.js'))'>
                <div class="min-w-0">
                    <strong class="block">{{ __('push.title') }}</strong>
                    <span class="ss-help block !mt-0.5" data-push-text>{{ \App\Services\Push\PushSender::enabled() ? __('push.js.checking') : __('push.not_configured') }}</span>
                    <span class="ss-help block !mt-0.5">{{ trans_choice('push.devices', $user->pushSubscriptions()->count(), ['count' => $user->pushSubscriptions()->count()]) }}</span>
                </div>
                <button type="button" class="ss-btn ss-btn-secondary ss-btn-sm" data-push-toggle hidden></button>
            </div>
        </section>
    </div>
</x-layouts.app>
