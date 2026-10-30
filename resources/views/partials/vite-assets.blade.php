{{-- Fonts and the shared bundle (built into public/build; the server has no Node). --}}
@php $interFont = rescue(fn () => \Illuminate\Support\Facades\Vite::asset('resources/fonts/inter-latin-wght.woff2'), null, report: false); @endphp
@if (filled($interFont))
    <link rel="preload" href="{{ $interFont }}" as="font" type="font/woff2" crossorigin>
@endif
@vite(['resources/css/app.css', 'resources/js/app.js'])
