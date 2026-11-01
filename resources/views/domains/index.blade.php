@php
    use App\Models\Domain;
    use App\Support\Format;
    use App\Support\Label;
    use App\Support\Time;

    $tabs = [
        null => ['all', $counts['all']],
        'critical' => ['critical', $counts['critical']],
        'warning' => ['warning', $counts['warning']],
        'healthy' => ['healthy', $counts['healthy']],
        'unknown' => ['unknown', $counts['unknown']],
        'paused' => ['paused', $counts['paused']],
    ];

    if (auth()->user()->can('admin')) {
        $tabs['deleted'] = ['deleted', $counts['deleted']];
    }

    $days = fn (?int $d) => $d === null ? null : ($d < 0 ? __('domains.expired') : __('domains.days', ['days' => $d]));
@endphp

<x-layouts.app :title="__('domains.title')">
    <x-slot:subtitle>{{ __('domains.index.subtitle') }}</x-slot:subtitle>

    <x-slot:actions>
        @can('admin')
            <a href="{{ route('domains.create') }}" class="ss-btn ss-btn-primary"><svg class="ico"><use href="#i-plus"/></svg> {{ __('domains.add_button') }}</a>
        @endcan
    </x-slot:actions>

    <section class="mb-4 grid grid-cols-2 gap-3 xl:grid-cols-4" aria-label="{{ __('domains.index.stats_label') }}">
        <article class="ss-stat">
            <span class="ss-stat-icon"><svg class="ico"><use href="#i-globe"/></svg></span>
            <div class="min-w-0"><div class="ss-stat-label">{{ __('domains.index.stats.monitored') }}</div><div class="ss-stat-value">{{ $counts['all'] - $counts['paused'] }}</div></div>
        </article>
        <article @class(['ss-stat', 'is-ok' => $counts['healthy'] > 0])>
            <span class="ss-stat-icon"><svg class="ico"><use href="#i-check"/></svg></span>
            <div class="min-w-0"><div class="ss-stat-label">{{ __('domains.index.stats.healthy') }}</div><div class="ss-stat-value">{{ $counts['healthy'] }}</div></div>
        </article>
        <article @class(['ss-stat', 'is-warn' => $counts['warning'] > 0])>
            <span class="ss-stat-icon"><svg class="ico"><use href="#i-alert"/></svg></span>
            <div class="min-w-0"><div class="ss-stat-label">{{ __('domains.index.stats.warning') }}</div><div class="ss-stat-value">{{ $counts['warning'] }}</div></div>
        </article>
        <article @class(['ss-stat', 'is-bad' => $counts['critical'] > 0])>
            <span class="ss-stat-icon"><svg class="ico"><use href="#i-siren"/></svg></span>
            <div class="min-w-0"><div class="ss-stat-label">{{ __('domains.index.stats.critical') }}</div><div class="ss-stat-value">{{ $counts['critical'] }}</div></div>
        </article>
    </section>

    <section class="ss-panel">
        <nav class="ss-tabs-scroll mb-4" aria-label="{{ __('domains.index.filter_label') }}">
            <div class="ss-segmented">
                @foreach ($tabs as $key => [$label, $count])
                    <a href="{{ route('domains.index', array_filter([...request()->only(['q', 'sort']), 'status' => $key])) }}"
                       @if ($status === $key) aria-current="page" @endif>
                        {{ __('domains.index.tabs.'.$label) }} <span class="ss-count">{{ $count }}</span>
                    </a>
                @endforeach
            </div>
        </nav>

        <form method="GET" action="{{ route('domains.index') }}" class="mb-4 grid gap-3 sm:grid-cols-[minmax(0,1fr)_auto_auto] sm:items-end" role="search">
            @if ($status)<input type="hidden" name="status" value="{{ $status }}">@endif
            <div>
                <label class="ss-label" for="q">{{ __('domains.index.search') }}</label>
                <input id="q" class="ss-input mt-1.5" type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="{{ __('domains.index.search_placeholder') }}">
            </div>
            <div>
                <label class="ss-label" for="sort">{{ __('domains.index.sort') }}</label>
                <select id="sort" class="ss-input mt-1.5" name="sort">
                    @foreach (['status', 'name', 'ssl', 'expiry', 'response'] as $value)
                        <option value="{{ $value }}" @selected(($filters['sort'] ?? 'status') === $value)>{{ __('domains.index.sorts.'.$value) }}</option>
                    @endforeach
                </select>
            </div>
            <label class="flex min-h-11 items-center gap-2 text-sm"><input type="checkbox" name="favorites" value="1" @checked(! empty($filters['favorites']))> {{ __('favorites.filter') }}</label>
            <div>
                <button class="ss-btn ss-btn-secondary" type="submit"><svg class="ico"><use href="#i-search"/></svg> {{ __('domains.index.filter') }}</button>
            </div>
        </form>

        @if ($domains->isEmpty())
            @if ($counts['all'] === 0 && ! $status)
                <x-empty-state icon="i-globe" :title="__('domains.index.empty_title')">
                    {{ __('domains.index.empty_text') }}
                    @can('admin')
                        <x-slot:actions><a href="{{ route('domains.create') }}" class="ss-btn ss-btn-primary"><svg class="ico"><use href="#i-plus"/></svg> {{ __('domains.index.add_first') }}</a></x-slot:actions>
                    @endcan
                </x-empty-state>
            @else
                <x-empty-state icon="i-search" :title="__('domains.index.none_found')">{{ __('domains.index.none_found_text') }}</x-empty-state>
            @endif
        @else
            <div class="ss-table-wrap">
                <table class="ss-table ss-table-cards">
                    <thead>
                        <tr>
                            <th scope="col">{{ __('domains.index.col_domain') }}</th>
                            <th scope="col">{{ __('domains.index.col_status') }}</th>
                            <th scope="col">{{ __('domains.index.col_http') }}</th>
                            <th scope="col" class="num">{{ __('domains.index.col_response') }}</th>
                            <th scope="col">{{ __('domains.index.col_certificate') }}</th>
                            <th scope="col">{{ __('domains.index.col_registration') }}</th>
                            <th scope="col">{{ __('domains.index.col_last_check') }}</th>
                            <th scope="col"><span class="sr-only">{{ __('domains.actions.column') }}</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($domains as $domain)
                            @php
                                $sslDays = $domain->sslDaysRemaining();
                                $expDays = $domain->expiry_status === 'verified' ? $domain->expiryDaysRemaining() : null;
                                $lastCheck = $domain->http_last_checked_at ?? $domain->dns_last_checked_at;
                            @endphp
                            <tr>
                                <td class="is-primary">
                                    @unless ($domain->trashed())<x-favorite-button type="domain" :id="$domain->id" :active="in_array($domain->id, $favorites, true)" class="float-right" />@endunless
                                    <a class="font-semibold text-fg no-underline hover:text-accent [overflow-wrap:anywhere]" href="{{ route('domains.show', $domain) }}">{{ $domain->displayName() }}</a>
                                    <span class="block text-xs text-muted [overflow-wrap:anywhere]">{{ $domain->url() }}</span>
                                </td>
                                <td data-label="{{ __('domains.index.col_status') }}">
                                    @if ($domain->trashed())
                                        <x-status status="closed" :label="__('domains.deleted')" />
                                    @else
                                        <x-health :state="\App\Support\Health::domain($domain)" />
                                    @endif
                                </td>
                                <td data-label="{{ __('domains.index.col_http') }}">
                                    @if (! $domain->http_enabled)
                                        <span class="text-xs text-muted">{{ __('domains.off') }}</span>
                                    @else
                                        <span>
                                            <x-status :status="$domain->http_status" />
                                            @if ($domain->http_consecutive_failures > 0 && $domain->http_status !== 'down')
                                                <span class="block text-xs text-muted">{{ __('domains.index.failed_times', ['count' => $domain->http_consecutive_failures]) }}</span>
                                            @endif
                                        </span>
                                    @endif
                                </td>
                                <td data-label="{{ __('domains.index.col_response') }}" class="num">{{ $domain->http_last_response_ms !== null ? Format::number($domain->http_last_response_ms).' ms' : '—' }}</td>
                                <td data-label="{{ __('domains.index.col_certificate') }}">
                                    @if (! $domain->ssl_enabled)
                                        <span class="text-xs text-muted">{{ __('domains.off') }}</span>
                                    @elseif ($sslDays !== null)
                                        <span>
                                            <span @class(['ss-text-bad' => $sslDays < 0 || in_array($domain->ssl_status, Domain::SSL_INVALID_STATES, true), 'ss-text-warn' => $domain->ssl_status === 'expiring'])>{{ $days($sslDays) }}</span>
                                            @if ($domain->ssl_status !== 'valid')<span class="block text-xs text-muted">{{ str_replace('_', ' ', $domain->ssl_status) }}</span>@endif
                                        </span>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td data-label="{{ __('domains.index.col_registration') }}">
                                    @if (! $domain->expiry_enabled)
                                        <span class="text-xs text-muted">{{ __('domains.off') }}</span>
                                    @elseif ($expDays !== null)
                                        <span @class(['ss-text-bad' => $expDays < 0, 'ss-text-warn' => $expDays >= 0 && $expDays <= max($domain->expiryWarningDays())])>{{ $days($expDays) }}</span>
                                    @else
                                        <span class="text-xs text-muted" title="{{ $domain->expiry_message }}">{{ Lang::has('domains.index.expiry.'.$domain->expiry_status) ? __('domains.index.expiry.'.$domain->expiry_status) : $domain->expiry_status }}</span>
                                    @endif
                                </td>
                                <td data-label="{{ __('domains.index.col_last_check') }}" class="whitespace-nowrap text-muted" title="{{ Time::format($lastCheck, 'd-m-Y H:i:s') }}">{{ Time::relative($lastCheck) }}</td>
                                <td class="is-actions">
                                    @unless ($domain->trashed())
                                        <x-row-actions :label="__('domains.actions.menu', ['name' => $domain->displayName()])" :links="[
                                            [__('domains.actions.view'), route('domains.show', $domain), 'i-globe'],
                                            [__('domains.actions.http'), route('domains.show', $domain).'#availability', 'i-pulse'],
                                            [__('domains.actions.ssl'), route('domains.show', $domain).'#certificate', 'i-lock'],
                                            [__('domains.actions.dns'), route('domains.show', $domain).'#dns', 'i-dns'],
                                            [__('domains.actions.expiry'), route('domains.show', $domain).'#certificate', 'i-calendar'],
                                            [__('domains.actions.incidents'), route('incidents.index', ['domain' => $domain->id, 'state' => 'all']), 'i-siren'],
                                        ]">
                                            @can('admin')
                                                <a class="ss-menu-item" role="menuitem" href="{{ route('domains.create', ['from' => $domain->id]) }}"><svg class="ico" aria-hidden="true"><use href="#i-copy"/></svg>{{ __('domains.actions.duplicate') }}</a>
                                                <form method="POST" action="{{ route($domain->is_active ? 'domains.pause' : 'domains.resume', $domain) }}" data-confirm="{{ __($domain->is_active ? 'domains.actions.pause_confirm' : 'domains.actions.resume_confirm', ['name' => $domain->displayName()]) }}">
                                                    @csrf
                                                    <button class="ss-menu-item" role="menuitem" type="submit"><svg class="ico" aria-hidden="true"><use href="#{{ $domain->is_active ? 'i-pause' : 'i-play' }}"/></svg>{{ __($domain->is_active ? 'domains.actions.pause' : 'domains.actions.resume') }}</button>
                                                </form>
                                            @endcan
                                        </x-row-actions>
                                    @endunless
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <x-pagination :paginator="$domains" />
        @endif
    </section>
</x-layouts.app>
