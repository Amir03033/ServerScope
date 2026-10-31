@props([
    'title' => null,
    'flash' => true,
])

@php
    $user = auth()->user();
    // Rights come from the role in the current team, never from users.is_admin.
    $isAdmin = (bool) $user?->can('admin');
    $isPlatform = (bool) $user?->can('platform');
    $teams = $user ? $user->teams()->orderBy('name')->get(['teams.id', 'teams.name']) : collect();
    $locale = app()->getLocale();

    // Navigation entries only appear once their route exists, so the shell
    // can be shared by features that are introduced incrementally.
    $adminItems = [
        ['route' => 'alert-rules.index', 'match' => 'alert-rules.*', 'icon' => 'i-sliders', 'label' => 'alert_rules', 'admin' => true],
        ['route' => 'status-pages.index', 'match' => 'status-pages.*', 'icon' => 'i-status', 'label' => 'status_pages', 'admin' => true],
        ['route' => 'webhooks.index', 'match' => 'webhooks.*', 'icon' => 'i-webhook', 'label' => 'webhooks', 'admin' => true],
        ['route' => 'users.index', 'match' => 'users.*', 'icon' => 'i-users', 'label' => 'users', 'admin' => true],
        ['route' => 'audit-logs.index', 'match' => 'audit-logs.*', 'icon' => 'i-history', 'label' => 'audit_log', 'admin' => true],
        ['route' => 'system-health', 'match' => 'system-health', 'icon' => 'i-pulse', 'label' => 'system_health', 'admin' => true, 'platform' => true],
        ['route' => 'platform.agent-releases', 'match' => 'platform.agent-releases', 'icon' => 'i-key', 'label' => 'agent_releases', 'platform' => true],
    ];

    $navGroups = [
        '' => [
            ['route' => 'dashboard', 'match' => 'dashboard', 'icon' => 'i-home', 'label' => 'dashboard'],
        ],
        'monitoring' => [
            ['route' => 'servers.index', 'match' => 'servers.*', 'icon' => 'i-server', 'label' => 'servers'],
            ['route' => 'domains.index', 'match' => ['domains.*', 'websites.*', 'pages.*'], 'icon' => 'i-globe', 'label' => 'domains'],
            ['route' => 'dependencies.index', 'match' => 'dependencies.*', 'icon' => 'i-link', 'label' => 'dependencies'],
        ],
        'incidents' => [
            ['route' => 'incidents.index', 'match' => 'incidents.*', 'icon' => 'i-siren', 'label' => 'incidents', 'count' => $sidebarOpenIncidents ?? 0],
            ['route' => 'alerts.index', 'match' => 'alerts.*', 'icon' => 'i-bell', 'label' => 'alerts', 'count' => $sidebarActiveAlerts ?? 0],
        ],
        'reporting' => [
            ['route' => 'reports.index', 'match' => 'reports.*', 'icon' => 'i-bars', 'label' => 'reports'],
        ],
        // Team admins see the admin pages, platform admins the platform pages.
        'admin' => array_values(array_filter($adminItems, fn ($item) => ($isAdmin && ($item['admin'] ?? false)) || ($isPlatform && ($item['platform'] ?? false)))),
        'account' => [
            ['route' => 'notifications.index', 'match' => 'notifications.*', 'icon' => 'i-message', 'label' => 'notifications', 'count' => $sidebarUnreadNotifications ?? 0, 'countClass' => 'info'],
            ['route' => 'profile.edit', 'match' => ['profile.*', 'api-tokens.*'], 'icon' => 'i-user', 'label' => 'profile'],
        ],
    ];

    $systemStatus = $isPlatform
        ? app(\App\Services\SystemHealth::class)->summary()
        : ($isAdmin ? app(\App\Services\SystemHealth::class)->summary(\App\Tenancy\Tenant::id()) : null);
    $systemLabel = __('nav.system.'.(in_array($systemStatus, ['healthy', 'warning', 'critical'], true) ? $systemStatus : 'unknown'));
    $systemDot = ['healthy' => 'ss-dot-ok', 'warning' => 'ss-dot-warn', 'critical' => 'ss-dot-bad'][$systemStatus] ?? 'ss-dot-ok';

    $initials = collect(preg_split('/\s+/', trim((string) $user?->name)))->filter()->take(2)->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))->implode('') ?: 'S';
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <meta name="theme-color" content="#fafafa" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#0a0a0b" media="(prefers-color-scheme: dark)">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ? $title.' — ServerScope' : 'ServerScope' }}</title>
    {{-- Applies the saved theme before the first paint (no flash). --}}
    <script src="{{ asset('js/theme-init.js') }}?v={{ @filemtime(public_path('js/theme-init.js')) }}"></script>
    @include('partials.vite-assets')
    {{ $head ?? '' }}
</head>
<body>
@include('partials.icons')

<a href="#main" class="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-[70] focus:rounded-lg focus:bg-accent focus:px-4 focus:py-2 focus:text-on-accent">{{ __('nav.skip') }}</a>

<div class="ss-shell">
    <aside class="ss-sidebar" id="sidebar" data-sidebar aria-label="{{ __('nav.main') }}">
        <div class="flex h-[3.75rem] shrink-0 items-center justify-between gap-2 border-b border-line px-4">
            <a href="{{ route('dashboard') }}" class="flex min-w-0 items-center gap-2.5 text-fg no-underline">
                <svg class="ico h-6 w-6 text-accent [stroke-width:2]"><use href="#i-pulse"/></svg>
                <span class="min-w-0">
                    <span class="block text-[15px] font-semibold leading-tight tracking-tight">ServerScope</span>
                    <span class="block truncate text-xs text-muted">{{ __('nav.brand_caption') }}</span>
                </span>
            </a>
            <a href="#" class="ss-icon-btn ss-sidebar-close" data-sidebar-close aria-label="{{ __('nav.close_menu') }}"><svg class="ico"><use href="#i-x"/></svg></a>
        </div>

        <nav aria-label="{{ __('nav.main') }}" class="flex-1 px-3 pb-4 pt-2">
            @foreach ($navGroups as $group => $items)
                @php $items = array_filter($items, fn ($item) => Route::has($item['route'])); @endphp
                @continue(empty($items))

                @if ($group !== '')
                    <div class="ss-nav-group">{{ __('nav.groups.'.$group) }}</div>
                @endif

                @foreach ($items as $item)
                    @php $isActive = request()->routeIs(...(array) $item['match']); @endphp
                    <a href="{{ route($item['route']) }}" class="ss-nav-item" @if ($isActive) aria-current="page" @endif>
                        <svg class="ico"><use href="#{{ $item['icon'] }}"/></svg>
                        <span class="min-w-0 truncate">{{ __('nav.items.'.$item['label']) }}</span>
                        @if (($item['count'] ?? 0) > 0)
                            <span class="nav-count {{ $item['countClass'] ?? '' }}">{{ $item['count'] }}</span>
                        @endif
                    </a>
                @endforeach
            @endforeach
        </nav>

        <div class="m-3 mt-0 rounded-xl border border-line bg-surface p-3 text-[13px]">
            <div class="flex items-center gap-2 font-semibold text-fg">
                <span class="ss-dot {{ $systemDot }}" aria-hidden="true"></span>{{ $systemLabel }}
            </div>
            <div class="mt-1 text-xs text-muted">
                @if ($systemStatus)
                    <a class="text-muted underline-offset-2 hover:text-fg hover:underline" href="{{ route('system-health') }}">{{ $isPlatform ? __('nav.system.link_platform') : __('nav.system.link_team') }}</a>
                @else
                    {{ __('nav.system.agent_connection') }}
                @endif
            </div>
            @if ($teams->count() > 1)
                <form method="POST" action="{{ route('teams.switch', $user->current_team_id ?? 0) }}" class="mt-3" data-team-switch>
                    @csrf
                    <label class="mb-1 block text-xs text-muted" for="team-switch">{{ __('nav.team.label') }}</label>
                    <select id="team-switch" class="ss-input !py-1.5 !text-sm">
                        @foreach ($teams as $team)
                            <option value="{{ $team->id }}" @selected($team->id === $user->current_team_id)>{{ $team->name }}</option>
                        @endforeach
                    </select>
                    <noscript><button class="ss-btn ss-btn-secondary ss-btn-sm mt-2" type="submit">{{ __('nav.team.label') }}</button></noscript>
                </form>
            @elseif ($teams->isNotEmpty())
                <div class="mt-2 text-xs text-muted">{{ __('nav.team.current', ['team' => $teams->first()->name]) }}</div>
            @endif
            @if (config('serverscope.docs_url'))
                <a class="mt-2 inline-flex items-center gap-1.5 text-xs text-muted hover:text-fg" href="{{ config('serverscope.docs_url') }}" target="_blank" rel="noopener"><svg class="ico"><use href="#i-file"/></svg> {{ __('nav.documentation') }}</a>
            @endif
        </div>
    </aside>
    <a href="#" class="ss-sidebar-backdrop" data-sidebar-close tabindex="-1" aria-hidden="true"></a>

    <div class="min-w-0">
        <header class="ss-topbar">
            <a href="#sidebar" class="ss-icon-btn lg:hidden" data-sidebar-open aria-controls="sidebar" aria-expanded="false" aria-label="{{ __('nav.open_menu') }}"><svg class="ico"><use href="#i-menu"/></svg></a>
            <a href="{{ route('dashboard') }}" class="flex items-center gap-2 text-fg no-underline lg:hidden" aria-label="ServerScope">
                <svg class="ico h-5 w-5 text-accent [stroke-width:2]"><use href="#i-pulse"/></svg>
                <span class="text-[15px] font-semibold tracking-tight">ServerScope</span>
            </a>

            <div class="ml-auto flex items-center gap-1.5">
                @if (Route::has('search'))
                    <a href="{{ route('search') }}" data-search-open
                       class="hidden h-9 items-center gap-2 rounded-lg border border-line bg-surface pl-2.5 pr-1.5 text-sm text-muted no-underline transition-colors hover:border-line-strong hover:text-fg sm:flex"
                       aria-label="{{ __('nav.search.hint') }}" aria-keyshortcuts="Control+K Meta+K">
                        <svg class="ico h-4 w-4"><use href="#i-search"/></svg>
                        <span class="hidden md:inline">{{ __('nav.search.open') }}</span>
                        <kbd class="ss-kbd" data-shortcut-label>Ctrl K</kbd>
                    </a>
                    <a href="{{ route('search') }}" data-search-open class="ss-icon-btn sm:hidden" aria-label="{{ __('nav.search.hint') }}"><svg class="ico"><use href="#i-search"/></svg></a>
                @endif

                <button type="button" class="ss-icon-btn" data-theme-toggle hidden
                        aria-label="{{ __('nav.theme.label') }}: {{ __('nav.theme.system') }}"
                        data-label-prefix="{{ __('nav.theme.label') }}"
                        data-labels='@json(['system' => __('nav.theme.system'), 'light' => __('nav.theme.light'), 'dark' => __('nav.theme.dark')])'>
                    <svg class="ico" data-theme-icon="system"><use href="#i-monitor"/></svg>
                    <svg class="ico" data-theme-icon="light" hidden><use href="#i-sun"/></svg>
                    <svg class="ico" data-theme-icon="dark" hidden><use href="#i-moon"/></svg>
                </button>

                <details class="ss-menu" data-menu>
                    <summary class="flex cursor-pointer items-center rounded-full" aria-label="{{ __('nav.user.menu') }}">
                        <span class="ss-avatar" aria-hidden="true">{{ $initials }}</span>
                    </summary>
                    <div class="ss-menu-panel">
                        <div class="border-b border-line px-2.5 pb-2.5 pt-1.5">
                            <div class="truncate text-sm font-semibold text-fg">{{ $user?->name }}</div>
                            <div class="truncate text-xs text-muted">{{ __('nav.user.signed_in_as', ['email' => $user?->email]) }}</div>
                        </div>
                        <div class="py-1">
                            @if (Route::has('profile.edit'))
                                <a class="ss-menu-item" href="{{ route('profile.edit') }}"><svg class="ico"><use href="#i-user"/></svg>{{ __('nav.user.profile') }}</a>
                            @endif
                        </div>
                        <form method="POST" action="{{ route('locale.update') }}" class="border-t border-line px-2.5 py-2.5" data-no-loading>
                            @csrf
                            <div class="mb-1.5 flex items-center gap-2 text-xs text-muted"><svg class="ico"><use href="#i-languages"/></svg>{{ __('nav.user.language') }}</div>
                            <div class="ss-segmented w-full">
                                @foreach (\App\Support\Locale::SUPPORTED as $code)
                                    <button type="submit" name="locale" value="{{ $code }}" lang="{{ $code }}" class="flex-1" aria-pressed="{{ $code === $locale ? 'true' : 'false' }}">{{ __('locale.names.'.$code) }}</button>
                                @endforeach
                            </div>
                        </form>
                        <form method="POST" action="{{ route('logout') }}" class="border-t border-line pt-1">
                            @csrf
                            <button class="ss-menu-item" type="submit"><svg class="ico"><use href="#i-logout"/></svg>{{ __('nav.user.logout') }}</button>
                        </form>
                    </div>
                </details>
            </div>
        </header>

        <main id="main" tabindex="-1" class="ss-content focus:outline-none">
            <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div class="min-w-0">
                    <div class="ss-breadcrumb">
                        <svg class="ico"><use href="#i-home"/></svg>
                        @isset($breadcrumb)
                            {{ $breadcrumb }}
                        @else
                            <span>›</span> {{ $title }}
                        @endisset
                    </div>
                    <h1 class="ss-page-title">{{ $pageTitle ?? $title }}</h1>
                    @isset($subtitle)
                        <p class="ss-page-subtitle">{{ $subtitle }}</p>
                    @endisset
                </div>

                @isset($actions)
                    <div class="actions">{{ $actions }}</div>
                @endisset
            </div>

            @php($planTeam = $user?->current_team_id ? \App\Models\Team::query()->find($user->current_team_id) : null)
            @if ($planTeam?->isPendingPayment())
                <div class="ss-alert ss-alert-warning mb-5" role="status" data-testid="plan-pending-banner">
                    <svg class="ico"><use href="#i-alert"/></svg>
                    <div>{{ __('plans.banner.pending', ['plan' => __('plans.names.'.$planTeam->plan)]) }}</div>
                </div>
            @endif

            @if ($flash && (session('success') || session('error') || $errors->any()))
                <div class="mb-5 grid gap-3">
                    @if (session('success'))
                        <div class="ss-alert ss-alert-success" role="status">
                            <svg class="ico"><use href="#i-check"/></svg>
                            <div>{{ session('success') }}</div>
                        </div>
                    @endif

                    @if (session('error'))
                        <div class="ss-alert ss-alert-error" role="alert">
                            <svg class="ico"><use href="#i-alert"/></svg>
                            <div>{{ session('error') }}</div>
                        </div>
                    @endif

                    @if ($errors->any())
                        <div class="ss-alert ss-alert-error" role="alert">
                            <svg class="ico"><use href="#i-alert"/></svg>
                            <div>
                                <strong>{{ __('nav.flash.check_input') }}</strong>
                                <ul>
                                    @foreach ($errors->all() as $error)
                                        <li>{{ $error }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        </div>
                    @endif
                </div>
            @endif

            <div class="ss-rise">
                {{ $slot }}
            </div>

            <footer class="mt-10 flex flex-wrap justify-between gap-3 border-t border-line pt-4 text-xs text-muted">
                <span>{{ __('nav.footer') }}</span>
                <span>{{ $user?->email }}</span>
            </footer>
        </main>
    </div>
</div>

@include('partials.search-dialog')
@include('partials.confirm-dialog')
@include('partials.i18n')
</body>
</html>
