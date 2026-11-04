<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <title>{{ __('postmortems.page_title', ['title' => $pm['incident']->title]) }}</title>
    <script src="{{ asset('js/theme-init.js') }}?v={{ @filemtime(public_path('js/theme-init.js')) }}"></script>
    @include('partials.vite-assets')
</head>
<body class="ss-doc-public">
@include('partials.icons')
<main class="ss-doc-shell">
    @include('incidents._postmortem-document', ['public' => true])
    <footer class="ss-doc-footer">
        <svg class="ico h-4 w-4 text-accent" aria-hidden="true"><use href="#i-pulse"/></svg>
        {{ __('postmortems.public_footer') }}
    </footer>
</main>
</body>
</html>
