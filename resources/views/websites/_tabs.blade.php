{{-- Navigation between the domain, its pages and discovery. --}}
<nav class="ss-tabs-scroll mb-4" aria-label="{{ __('websites.tabs.label') }}">
    <div class="ss-segmented">
        <a href="{{ route('domains.show', $domain) }}" @if (request()->routeIs('domains.show')) aria-current="page" @endif>{{ __('websites.tabs.domain') }}</a>
        <a href="{{ route('websites.pages', $domain) }}" @if (request()->routeIs('websites.pages', 'pages.*')) aria-current="page" @endif>{{ __('websites.tabs.pages') }}</a>
        <a href="{{ route('websites.discovery', $domain) }}" @if (request()->routeIs('websites.discovery')) aria-current="page" @endif>{{ __('websites.tabs.discovery') }}</a>
    </div>
</nav>
