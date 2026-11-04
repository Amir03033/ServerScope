@props(['title'])
@php $locale = app()->getLocale(); @endphp
<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <meta name="theme-color" content="#fafafa" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#0a0a0b" media="(prefers-color-scheme: dark)">
    <title>{{ $title }} — ServerScope</title>
    <script src="{{ asset('js/theme-init.js') }}?v={{ @filemtime(public_path('js/theme-init.js')) }}"></script>
    @include('partials.vite-assets')
</head>
<body class="ss-auth-body">
@include('partials.icons')

<div class="ss-auth">
    {{-- The first thing a visitor sees: what ServerScope watches, alive. Decorative; the form works without it. --}}
    <aside class="ss-auth-show" aria-hidden="true">
        <div class="ss-auth-aurora"><span></span><span></span><span></span></div>
        <div class="ss-auth-grid"></div>

        <div class="ss-auth-show-inner">
            <span class="ss-auth-brand"><x-live-logo class="h-7 w-7" /> ServerScope</span>

            <div class="ss-auth-pitch">
                <h2>{{ __('auth.showcase.title') }}</h2>
                <p>{{ __('auth.showcase.text') }}</p>
            </div>

            <div class="ss-auth-stage">
                <svg class="ss-auth-ecg" viewBox="0 0 600 120" preserveAspectRatio="none">
                    <path class="base" d="M0 60 H140 L160 60 L172 28 L188 96 L202 44 L214 60 H330 L350 60 L362 24 L378 100 L392 40 L404 60 H600"/>
                    <path class="beat" d="M0 60 H140 L160 60 L172 28 L188 96 L202 44 L214 60 H330 L350 60 L362 24 L378 100 L392 40 L404 60 H600"/>
                </svg>

                <div class="ss-auth-card is-a">
                    <span class="ss-auth-dot is-ok"></span>
                    <span><strong>web-01</strong><small>{{ __('auth.showcase.cards.online') }} · CPU 23%</small></span>
                </div>
                <div class="ss-auth-card is-b">
                    <span class="ss-auth-dot is-ok"></span>
                    <span><strong>shop.example.com</strong><small>142 ms · {{ __('auth.showcase.cards.ssl') }}</small></span>
                </div>
                <div class="ss-auth-card is-c">
                    <span class="ss-auth-badge"><svg class="ico"><use href="#i-sparkles"/></svg></span>
                    <span><strong>{{ __('auth.showcase.cards.cause') }}</strong><small>{{ __('auth.showcase.cards.cause_text') }}</small></span>
                </div>
                <div class="ss-auth-card is-d">
                    <span class="ss-auth-dot is-warn"></span>
                    <span><strong>{{ __('auth.showcase.cards.resolved') }}</strong><small>{{ __('auth.showcase.cards.resolved_text') }}</small></span>
                </div>
            </div>

            <ul class="ss-auth-points">
                @foreach (__('auth.showcase.points') as $point)
                    <li><svg class="ico"><use href="#i-check"/></svg>{{ $point }}</li>
                @endforeach
            </ul>
        </div>
    </aside>

    <div class="ss-auth-main">
        <header class="ss-auth-top">
            <span class="ss-auth-brand is-compact"><x-live-logo class="h-6 w-6" /> ServerScope</span>
            <div class="flex items-center gap-1.5">
                <x-locale-switch />
                <button type="button" class="ss-icon-btn" data-theme-toggle hidden
                        aria-label="{{ __('nav.theme.label') }}: {{ __('nav.theme.system') }}"
                        data-label-prefix="{{ __('nav.theme.label') }}"
                        data-labels='@json(['system' => __('nav.theme.system'), 'light' => __('nav.theme.light'), 'dark' => __('nav.theme.dark')])'>
                    <svg class="ico" data-theme-icon="system"><use href="#i-monitor"/></svg>
                    <svg class="ico" data-theme-icon="light" hidden><use href="#i-sun"/></svg>
                    <svg class="ico" data-theme-icon="dark" hidden><use href="#i-moon"/></svg>
                </button>
            </div>
        </header>

        <main id="main" class="ss-auth-center">
            <div class="ss-auth-panel">
                {{ $slot }}
            </div>
        </main>

        <footer class="ss-auth-foot">{{ __('auth.footer') }}</footer>
    </div>
</div>

@include('partials.i18n')
</body>
</html>
