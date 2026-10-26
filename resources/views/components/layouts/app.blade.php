@props([
    'title' => null,
    'flash' => true,
])

@php
    $user = auth()->user();
    $isAdmin = (bool) $user?->is_admin;

    // Navigation entries only appear once their route exists, so the shell
    // can be shared by features that are introduced incrementally.
    $navGroups = [
        null => [
            ['route' => 'dashboard', 'match' => 'dashboard', 'icon' => 'i-home', 'label' => 'Dashboard'],
            ['route' => 'servers.index', 'match' => 'servers.*', 'icon' => 'i-server', 'label' => 'Servers'],
            ['route' => 'domains.index', 'match' => 'domains.*', 'icon' => 'i-globe', 'label' => 'Domeinen'],
            ['route' => 'incidents.index', 'match' => 'incidents.*', 'icon' => 'i-siren', 'label' => 'Incidenten', 'count' => $sidebarOpenIncidents ?? 0],
            ['route' => 'alerts.index', 'match' => 'alerts.*', 'icon' => 'i-bell', 'label' => 'Alerts', 'count' => $sidebarActiveAlerts ?? 0],
            ['route' => 'dependencies.index', 'match' => 'dependencies.*', 'icon' => 'i-link', 'label' => 'Afhankelijkheden'],
            ['route' => 'reports.index', 'match' => 'reports.*', 'icon' => 'i-bars', 'label' => 'Rapporten'],
        ],
        'Beheer' => $isAdmin ? [
            ['route' => 'alert-rules.index', 'match' => 'alert-rules.*', 'icon' => 'i-sliders', 'label' => 'Alertregels'],
            ['route' => 'status-pages.index', 'match' => 'status-pages.*', 'icon' => 'i-status', 'label' => 'Statuspagina\'s'],
            ['route' => 'webhooks.index', 'match' => 'webhooks.*', 'icon' => 'i-webhook', 'label' => 'Webhooks'],
            ['route' => 'users.index', 'match' => 'users.*', 'icon' => 'i-users', 'label' => 'Gebruikers'],
            ['route' => 'audit-logs.index', 'match' => 'audit-logs.*', 'icon' => 'i-history', 'label' => 'Auditlog'],
        ] : [],
        'Account' => [
            ['route' => 'notifications.index', 'match' => 'notifications.*', 'icon' => 'i-message', 'label' => 'Meldingen', 'count' => $sidebarUnreadNotifications ?? 0, 'countClass' => 'info'],
            ['route' => 'profile.edit', 'match' => ['profile.*', 'api-tokens.*'], 'icon' => 'i-user', 'label' => 'Profiel'],
        ],
    ];
@endphp
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ? $title.' — ServerScope' : 'ServerScope' }}</title>
    <link rel="stylesheet" href="{{ asset('css/serverscope.css') }}?v={{ @filemtime(public_path('css/serverscope.css')) }}">
    {{ $head ?? '' }}
    @isset($styles)
        <style>{{ $styles }}</style>
    @endisset
</head>
<body>
@include('partials.icons')

<div class="layout">
    <aside class="sidebar">
        <a href="{{ route('dashboard') }}" class="brand">
            <svg class="ico"><use href="#i-pulse"/></svg>
            <div>
                <div class="brand-name">ServerScope</div>
                <div class="brand-caption">Server- en domeinmonitoring</div>
            </div>
        </a>

        <nav aria-label="Hoofdnavigatie">
            @foreach ($navGroups as $group => $items)
                @php $items = array_filter($items, fn ($item) => Route::has($item['route'])); @endphp
                @continue(empty($items))

                @if ($group)
                    <div class="nav-section">{{ $group }}</div>
                @endif

                @foreach ($items as $item)
                    @php $isActive = request()->routeIs(...(array) $item['match']); @endphp
                    <a
                        href="{{ route($item['route']) }}"
                        @class(['nav-item', 'active' => $isActive])
                        @if ($isActive) aria-current="page" @endif
                    >
                        <svg class="ico"><use href="#{{ $item['icon'] }}"/></svg>
                        {{ $item['label'] }}
                        @if (($item['count'] ?? 0) > 0)
                            <span class="nav-count {{ $item['countClass'] ?? '' }}">{{ $item['count'] }}</span>
                        @endif
                    </a>
                @endforeach
            @endforeach
        </nav>

        <div class="sidebar-bottom">
            <strong><span class="connection-dot"></span>Monitoring status</strong>
            <div class="muted small" style="margin-top:6px">Agentverbinding via HTTPS</div>
            @if (config('serverscope.docs_url'))
                <a class="small" style="display:inline-flex;align-items:center;gap:6px;margin-top:10px" href="{{ config('serverscope.docs_url') }}" target="_blank" rel="noopener"><svg class="ico"><use href="#i-file"/></svg> Documentatie</a>
            @endif
        </div>
    </aside>

    <main class="main">
        <header class="topbar">
            <div>
                <div class="breadcrumb">
                    <svg class="ico"><use href="#i-home"/></svg>
                    @isset($breadcrumb)
                        {{ $breadcrumb }}
                    @else
                        <span>›</span> {{ $title }}
                    @endisset
                </div>
                <h1 class="page-title">{{ $pageTitle ?? $title }}</h1>
                @isset($subtitle)
                    <p class="page-subtitle">{{ $subtitle }}</p>
                @endisset
            </div>

            @isset($actions)
                <div class="actions">{{ $actions }}</div>
            @endisset
        </header>

        <div class="account-bar">
            @if (Route::has('search'))
                <form method="GET" action="{{ route('search') }}" class="global-search" role="search">
                    <label class="sr-only" for="global-search">Zoeken</label>
                    <input id="global-search" type="search" name="q" placeholder="Zoek servers, domeinen, incidenten…" value="{{ request()->routeIs('search') ? request('q') : '' }}">
                </form>
            @endif
            <span>Ingelogd als {{ $user?->email }}</span>
            @if (Route::has('profile.edit'))
                <a href="{{ route('profile.edit') }}">Profiel</a>
            @endif
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button class="link-button" type="submit">Uitloggen</button>
            </form>
        </div>

        @if ($flash)
            @if (session('success'))
                <div class="flash" role="status">
                    <svg class="ico"><use href="#i-check"/></svg>
                    <div>{{ session('success') }}</div>
                </div>
            @endif

            @if (session('error'))
                <div class="flash error" role="alert">
                    <svg class="ico"><use href="#i-alert"/></svg>
                    <div>{{ session('error') }}</div>
                </div>
            @endif

            @if ($errors->any())
                <div class="flash error" role="alert">
                    <svg class="ico"><use href="#i-alert"/></svg>
                    <div>
                        <strong>Controleer de ingevoerde gegevens.</strong>
                        <ul>
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            @endif
        @endif

        {{ $slot }}

        <footer class="footer">
            <span>ServerScope · Infrastructure monitoring</span>
            <span>{{ $user?->email }}</span>
        </footer>
    </main>
</div>

<script>
    // Shared behaviour: confirmation for destructive actions and a loading
    // state on submit so forms cannot be submitted twice.
    document.addEventListener('submit', (event) => {
        const form = event.target;

        if (event.defaultPrevented) {
            return;
        }

        if (form.dataset.confirm && !window.confirm(form.dataset.confirm)) {
            event.preventDefault();
            return;
        }

        if (form.method.toLowerCase() === 'get' || form.hasAttribute('data-no-loading')) {
            return;
        }

        setTimeout(() => {
            form.querySelectorAll('button[type="submit"], button:not([type])').forEach((button) => {
                button.disabled = true;
                button.classList.add('is-loading');
            });
        }, 0);
    });
</script>
{{ $scripts ?? '' }}
</body>
</html>
