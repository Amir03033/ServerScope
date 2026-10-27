{{-- Navigation between the domain, its pages and discovery. --}}
<nav class="filters" aria-label="Website" style="margin-bottom:14px">
    <a href="{{ route('domains.show', $domain) }}" @class(['filter', 'active' => request()->routeIs('domains.show')])>Domein</a>
    <a href="{{ route('websites.pages', $domain) }}" @class(['filter', 'active' => request()->routeIs('websites.pages', 'pages.*')])>Website &amp; pagina's</a>
    <a href="{{ route('websites.discovery', $domain) }}" @class(['filter', 'active' => request()->routeIs('websites.discovery')])>Ontdekken</a>
</nav>
