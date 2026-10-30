@php
    use App\Support\Format;
    use App\Support\Time;
@endphp
<!DOCTYPE html>
{{-- Always light: made for paper. Same tokens and fonts as the app. --}}
<html lang="{{ app()->getLocale() }}" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>{{ __('reports.print_page.title', ['from' => Time::date($report['from']), 'to' => Time::date($report['to'])]) }} — ServerScope</title>
    @include('partials.vite-assets')
</head>
<body class="ss-print">
@include('partials.icons')
<main class="mx-auto max-w-6xl p-6">
    <div class="ss-noprint mb-4"><button class="ss-btn ss-btn-secondary" type="button" data-print><svg class="ico"><use href="#i-file"/></svg> {{ __('reports.print_page.button') }}</button></div>
    <h1 class="m-0 mb-1 text-2xl font-semibold tracking-tight">{{ __('reports.print_page.heading') }}</h1>
    <p class="mt-0 mb-4 text-sm text-muted">{{ __('reports.print_page.meta', ['from' => Time::dateTime($report['from']), 'to' => Time::dateTime($report['to']), 'zone' => Time::zone(), 'generated' => Time::dateTime(now()), 'target' => Format::percent($report['target'], 2)]) }}
        {{ __('reports.print_page.disclaimer') }}</p>
    @include('reports._body')
</main>
</body>
</html>
