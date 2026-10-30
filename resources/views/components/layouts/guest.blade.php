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
<body>
@include('partials.icons')

<div class="flex min-h-screen flex-col">
    <header class="flex items-center justify-between gap-3 px-4 py-4 sm:px-6">
        <span class="flex items-center gap-2 text-fg">
            <svg class="ico h-6 w-6 text-accent [stroke-width:2]"><use href="#i-pulse"/></svg>
            <span class="text-[15px] font-semibold tracking-tight">ServerScope</span>
        </span>
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

    <main id="main" class="flex flex-1 items-start justify-center px-4 pb-16 pt-6 sm:items-center sm:pt-0">
        <div class="ss-card ss-rise w-full max-w-[26rem] p-6 sm:p-8">
            {{ $slot }}
        </div>
    </main>

    <footer class="px-4 pb-6 text-center text-xs text-muted">{{ __('auth.footer') }}</footer>
</div>

@include('partials.i18n')
</body>
</html>
