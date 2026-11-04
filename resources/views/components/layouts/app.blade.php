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
        ['route' => 'billing.show', 'match' => 'billing.*', 'icon' => 'i-status', 'label' => 'billing', 'admin' => true],
        ['route' => 'audit-logs.index', 'match' => 'audit-logs.*', 'icon' => 'i-history', 'label' => 'audit_log', 'admin' => true],
        ['route' => 'system-health', 'match' => 'system-health', 'icon' => 'i-pulse', 'label' => 'system_health', 'admin' => true, 'platform' => true],
        ['route' => 'platform.commands', 'match' => 'platform.commands', 'icon' => 'i-sliders', 'label' => 'owner_commands', 'platform' => true],
        ['route' => 'platform.agent-releases', 'match' => 'platform.agent-releases', 'icon' => 'i-key', 'label' => 'agent_releases', 'platform' => true],
    ];

    $navGroups = [
        '' => [
            ['route' => 'dashboard', 'match' => 'dashboard', 'icon' => 'i-home', 'label' => 'dashboard'],
        ],
        'monitoring' => [
            ['route' => 'servers.index', 'match' => 'servers.*', 'icon' => 'i-server', 'label' => 'servers'],
            ['route' => 'domains.index', 'match' => ['domains.*', 'websites.*', 'pages.*'], 'icon' => 'i-globe', 'label' => 'domains'],
            // NEXUS with its views, folded in the menu (see the toggle below).
            ['route' => 'nexus.index', 'match' => ['nexus.index', 'nexus.graph'], 'icon' => 'i-nexus', 'label' => 'nexus', 'tree' => 'nexus', 'children' => [
                ['route' => 'nexus.skyline', 'match' => 'nexus.skyline', 'icon' => 'i-city', 'label' => 'skyline', 'sub' => true],
                ['route' => 'nexus.globe', 'match' => 'nexus.globe*', 'icon' => 'i-earth', 'label' => 'globe', 'sub' => true],
                ['route' => 'nexus.timelapse', 'match' => 'nexus.timelapse*', 'icon' => 'i-film', 'label' => 'timelapse', 'sub' => true],
                ['route' => 'nexus.wall', 'match' => 'nexus.wall', 'icon' => 'i-tv', 'label' => 'wall', 'sub' => true],
            ]],
            ['route' => 'dependencies.index', 'match' => 'dependencies.*', 'icon' => 'i-link', 'label' => 'dependencies'],
            ['route' => 'deployments.index', 'match' => ['deployments.*', 'deploy-sources.*'], 'icon' => 'i-flag', 'label' => 'deployments'],
            ['route' => 'logs.index', 'match' => 'logs.*', 'icon' => 'i-logs', 'label' => 'logs'],
            ['route' => 'heartbeats.index', 'match' => 'heartbeats.*', 'icon' => 'i-clock', 'label' => 'heartbeats'],
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
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml" data-live-icon>
    {{-- Installable app (manifest, service worker in resources/js/install.js). --}}
    <link rel="manifest" href="{{ asset('manifest.webmanifest') }}">
    <link rel="apple-touch-icon" href="{{ asset('icons/apple-touch-icon.png') }}">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="ServerScope">
    @if (Route::has('live.pulse') && \App\Tenancy\Tenant::check())<meta name="ss-live" content="{{ route('live.pulse') }}"><meta name="ss-wall" content="{{ route('nexus.wall') }}"><meta name="ss-peek" content="{{ url('/peek') }}">@endif
    @if (request()->routeIs('servers.index', 'domains.index', 'incidents.index', 'alerts.index'))<meta name="ss-live-list" content="30">@endif
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
                <svg class="ico ss-live-logo h-6 w-6 text-accent [stroke-width:2]" aria-hidden="true"><use class="base" href="#i-pulse"/><use class="beat" href="#i-pulse"/></svg>
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
                    @php $groupCount = collect($items)->sum(fn ($item) => (int) ($item['count'] ?? 0)); @endphp
                    <details class="ss-nav-section" data-nav-group="{{ $group }}" open>
                        <summary class="ss-nav-group">
                            <span>{{ __('nav.groups.'.$group) }}</span>
                            @if ($groupCount > 0)<span class="nav-count ss-nav-sumcount" aria-hidden="true">{{ $groupCount }}</span>@endif
                        </summary>
                @endif

                @foreach ($items as $item)
                    @php
                        $isActive = request()->routeIs(...(array) $item['match']);
                        $children = array_values(array_filter($item['children'] ?? [], fn ($child) => Route::has($child['route'])));
                        $inTree = $isActive || collect($children)->contains(fn ($child) => request()->routeIs(...(array) $child['match']));
                    @endphp
                    @if ($children !== [])<div class="ss-nav-tree{{ $inTree ? ' is-active' : '' }}" data-nav-tree="{{ $item['tree'] }}"><div class="ss-nav-tree-row">@endif
                    <a href="{{ route($item['route']) }}" class="ss-nav-item{{ ($item['sub'] ?? false) ? ' ss-nav-sub' : '' }}" @if ($isActive) aria-current="page" @endif>
                        <svg class="ico"><use href="#{{ $item['icon'] }}"/></svg>
                        <span class="min-w-0 truncate">{{ __('nav.items.'.$item['label']) }}</span>
                        @if (($item['count'] ?? 0) > 0)
                            <span class="nav-count {{ $item['countClass'] ?? '' }}">{{ $item['count'] }}</span>
                        @endif
                    </a>
                    @if ($children !== [])
                        <button type="button" class="ss-nav-toggle" data-nav-toggle="{{ $item['tree'] }}" aria-controls="nav-tree-{{ $item['tree'] }}" aria-expanded="{{ $inTree ? 'true' : 'false' }}" aria-label="{{ __('nav.toggle', ['name' => __('nav.items.'.$item['label'])]) }}"><svg class="ico" aria-hidden="true"><use href="#i-chevron-down"/></svg></button>
                        </div>
                        <div class="ss-nav-children" id="nav-tree-{{ $item['tree'] }}">
                            @foreach ($children as $child)
                                @php $childActive = request()->routeIs(...(array) $child['match']); @endphp
                                <a href="{{ route($child['route']) }}" class="ss-nav-item ss-nav-sub" @if ($childActive) aria-current="page" @endif>
                                    <svg class="ico"><use href="#{{ $child['icon'] }}"/></svg>
                                    <span class="min-w-0 truncate">{{ __('nav.items.'.$child['label']) }}</span>
                                </a>
                            @endforeach
                        </div>
                    </div>
                    @endif
                @endforeach

                @if ($group !== '')
                    </details>
                @endif
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
                <svg class="ico ss-live-logo h-5 w-5 text-accent [stroke-width:2]" aria-hidden="true"><use class="base" href="#i-pulse"/><use class="beat" href="#i-pulse"/></svg>
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
                        data-labels='@json(['system' => __('nav.theme.system'), 'light' => __('nav.theme.light'), 'dark' => __('nav.theme.dark')])'
                        data-variants='@json(['midnight' => __('themes.names.midnight'), 'terminal' => __('themes.names.terminal'), 'contrast' => __('themes.names.contrast')])'>
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
            @if ($planTeam?->isPendingPayment() && ! request()->routeIs('billing.*'))
                <div class="ss-alert ss-alert-warning mb-5" role="status" data-testid="plan-pending-banner">
                    <svg class="ico"><use href="#i-alert"/></svg>
                    <div>{{ __('plans.banner.pending', ['plan' => __('plans.names.'.$planTeam->plan)]) }}
                        @if (app(\App\Billing\BillingManager::class)->enabled() && $isAdmin)
                            <a class="underline" href="{{ route('billing.show') }}">{{ __('billing.banner_link') }}</a>
                        @endif
                    </div>
                </div>
            @endif

            @if ($planTeam && ! $planTeam->isPendingPayment() && $isAdmin && ($over = \App\Support\PlanLimits::overLimit($planTeam)))
                <div class="ss-alert ss-alert-warning mb-5" role="status" data-testid="plan-over-limit">
                    <svg class="ico"><use href="#i-alert"/></svg>
                    <div>{{ __('plans.banner.over_limit', ['items' => collect($over)->map(fn ($o, $k) => __('plans.items.'.$k).' '.$o['count'].'/'.$o['limit'])->implode(', ')]) }}</div>
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

{{-- Phones: a tab bar at the bottom (the menu stays under "More"). --}}
@if (Route::has('incidents.index') && Route::has('servers.index'))
<nav class="ss-tabbar" aria-label="{{ __('mobile.tabbar') }}">
    @foreach ([['dashboard', 'dashboard', 'i-home', 'overview'], ['incidents.index', 'incidents.*', 'i-siren', 'incidents'], ['servers.index', 'servers.*', 'i-server', 'servers']] as [$tabRoute, $tabMatch, $tabIcon, $tabLabel])
        <a href="{{ route($tabRoute) }}" class="ss-tab" @if (request()->routeIs($tabMatch)) aria-current="page" @endif>
            <span class="ss-tab-icon"><svg class="ico" aria-hidden="true"><use href="#{{ $tabIcon }}"/></svg>@if ($tabLabel === 'incidents' && ($sidebarOpenIncidents ?? 0) > 0)<span class="ss-tab-badge">{{ $sidebarOpenIncidents > 9 ? '9+' : $sidebarOpenIncidents }}</span>@endif</span>
            <span>{{ __('mobile.tabs.'.$tabLabel) }}</span>
        </a>
    @endforeach
    <a href="#sidebar" class="ss-tab" data-sidebar-open aria-controls="sidebar" aria-expanded="false">
        <span class="ss-tab-icon"><svg class="ico" aria-hidden="true"><use href="#i-menu"/></svg></span>
        <span>{{ __('mobile.tabs.more') }}</span>
    </a>
</nav>
@endif

@include('partials.search-dialog')
@include('partials.confirm-dialog')
@include('partials.i18n')
</body>
</html>
