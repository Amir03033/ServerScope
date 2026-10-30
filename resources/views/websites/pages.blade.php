@php
    use App\Models\MonitoredPage;
    use App\Support\Format;
    use App\Support\Label;
    use App\Support\Time;

    $ms = fn (?float $v) => $v === null ? '—' : Format::number($v).' ms';
    $pct = fn (?float $v, int $d = 2) => Format::percent($v, $d);
    $isAdmin = auth()->user()->can('admin');
    $tabs = [
        null => ['all', $counts['total'] + $counts['pending']],
        'down' => ['down', $counts['down']],
        'degraded' => ['degraded', $counts['degraded']],
        'up' => ['up', $counts['up']],
        'unknown' => ['unknown', $counts['unknown']],
        'stale' => ['stale', $counts['stale']],
        'paused' => ['paused', $counts['paused']],
        'pending' => ['pending', $counts['pending']],
        'rejected' => ['rejected', $counts['rejected']],
    ];
    $sortLink = function (string $key, string $label) use ($filters) {
        $current = ($filters['sort'] ?? 'status') === $key;
        $direction = $current && ($filters['direction'] ?? null) === 'asc' ? 'desc' : 'asc';

        return '<a class="ss-sort" href="'.e(route('websites.pages', [request()->route('domain'), ...request()->except(['page', 'sort', 'direction']), 'sort' => $key, 'direction' => $direction])).'">'.e($label).($current ? ' ↕' : '').'</a>';
    };
    $runStatus = ['completed' => 'valid', 'partial' => 'warning', 'failed' => 'failed', 'queued' => 'queued', 'running' => 'investigating'];
    $pageStatusLabel = fn (string $display) => in_array($display, ['pending', 'rejected', 'paused'], true)
        ? __('websites.'.$display)
        : Label::of('page_status', $display, MonitoredPage::STATUS_LABELS);
@endphp

<x-layouts.app :title="__('websites.pages.title', ['name' => $domain->displayName()])">
    <x-slot:breadcrumb><span>›</span> <a href="{{ route('domains.index') }}">{{ __('domains.title') }}</a> <span>›</span> <a href="{{ route('domains.show', $domain) }}">{{ $domain->displayName() }}</a> <span>›</span> {{ __('websites.pages.crumb') }}</x-slot:breadcrumb>
    <x-slot:subtitle>{{ __('websites.pages.subtitle') }}</x-slot:subtitle>
    <x-slot:actions>
        @if ($isAdmin && ! $domain->trashed())
            <a href="{{ route('websites.discovery', $domain) }}" class="ss-btn ss-btn-secondary"><svg class="ico"><use href="#i-search"/></svg> {{ __('websites.pages.discover') }}</a>
            <a href="{{ route('websites.pages.create', $domain) }}" class="ss-btn ss-btn-primary"><svg class="ico"><use href="#i-plus"/></svg> {{ __('websites.pages.add') }}</a>
        @endif
    </x-slot:actions>

    @include('websites._tabs')

    <div class="grid gap-4">
        @if ($counts['pending'] > 0)
            <div class="ss-alert ss-alert-info" role="status"><svg class="ico"><use href="#i-search"/></svg><div><strong>{{ trans_choice('websites.pages.pending_notice', $counts['pending'], ['count' => $counts['pending']]) }}</strong> {{ __('websites.pages.pending_text') }} <a class="text-accent" href="{{ route('websites.pages', [$domain, 'status' => 'pending']) }}">{{ __('websites.pages.view') }}</a></div></div>
        @endif

        <section class="grid grid-cols-2 gap-3 lg:grid-cols-3 xl:grid-cols-5" aria-label="{{ __('websites.pages.stats_label') }}">
            <article class="ss-stat">
                <span class="ss-stat-icon"><svg class="ico"><use href="#i-list"/></svg></span>
                <div class="min-w-0"><div class="ss-stat-label">{{ __('websites.pages.stats.monitored') }}</div><div class="ss-stat-value">{{ $counts['total'] }}</div><div class="ss-stat-caption">{{ __('websites.pages.stats.monitored_caption', ['paused' => $counts['paused'], 'pending' => $counts['pending']]) }}</div></div>
            </article>
            <article @class(['ss-stat', 'is-ok' => $counts['up'] > 0])>
                <span class="ss-stat-icon"><svg class="ico"><use href="#i-check"/></svg></span>
                <div class="min-w-0"><div class="ss-stat-label">{{ __('websites.pages.stats.healthy') }}</div><div class="ss-stat-value">{{ $counts['up'] }}</div><div class="ss-stat-caption">{{ __('websites.pages.stats.healthy_caption', ['count' => $counts['unknown']]) }}</div></div>
            </article>
            <article @class(['ss-stat', 'is-warn' => $counts['degraded'] > 0])>
                <span class="ss-stat-icon"><svg class="ico"><use href="#i-alert"/></svg></span>
                <div class="min-w-0"><div class="ss-stat-label">{{ __('websites.pages.stats.degraded') }}</div><div class="ss-stat-value">{{ $counts['degraded'] }}</div><div class="ss-stat-caption">{{ __('websites.pages.stats.degraded_caption') }}</div></div>
            </article>
            <article @class(['ss-stat', 'is-bad' => $counts['down'] > 0])>
                <span class="ss-stat-icon"><svg class="ico"><use href="#i-siren"/></svg></span>
                <div class="min-w-0"><div class="ss-stat-label">{{ __('websites.pages.stats.down') }}</div><div class="ss-stat-value">{{ $counts['down'] }}</div><div class="ss-stat-caption">{{ __('websites.pages.stats.down_caption', ['count' => $counts['stale']]) }}</div></div>
            </article>
            <article class="ss-stat col-span-2 lg:col-span-1">
                <span class="ss-stat-icon"><svg class="ico"><use href="#i-clock"/></svg></span>
                <div class="min-w-0"><div class="ss-stat-label">{{ __('websites.pages.stats.response') }}</div><div class="ss-stat-value">{{ $ms($stats24h['avg']) }}</div><div class="ss-stat-caption">{{ $stats24h['p95'] !== null ? __('websites.pages.stats.percentiles', ['p50' => $ms($stats24h['p50']), 'p95' => $ms($stats24h['p95']), 'p99' => $ms($stats24h['p99'])]) : __('websites.pages.stats.too_few') }}</div></div>
            </article>
        </section>

        <div class="grid gap-4 lg:grid-cols-2">
            <section class="ss-panel" aria-labelledby="availability-title">
                <div class="ss-panel-head"><div><h2 id="availability-title" class="ss-panel-title">{{ __('websites.pages.availability_title') }}</h2><p class="ss-panel-sub">{{ __('websites.pages.availability_text') }}</p></div></div>
                <div class="ss-strip" role="img" aria-label="{{ __('websites.pages.availability_label') }}">
                    @foreach ($daily as $day)
                        <i @class(['up' => $day['uptime'] === 100.0, 'partial' => $day['uptime'] !== null && $day['uptime'] < 100 && $day['uptime'] >= 95, 'down' => $day['uptime'] !== null && $day['uptime'] < 95])
                           title="{{ $day['date'] }}: {{ $day['uptime'] === null ? __('websites.pages.day_none') : __('websites.pages.day_value', ['uptime' => Format::percent($day['uptime'], 2), 'checks' => $day['checks']]) }}"></i>
                    @endforeach
                </div>
                <dl class="ss-dl mt-4">
                    <dt>{{ __('websites.pages.availability_24h_7d') }}</dt><dd>{{ $pct($stats24h['availability']) }} / {{ $pct($stats7d['availability']) }}</dd>
                    <dt>{{ __('websites.pages.checks_7d') }}</dt><dd>{{ __('websites.pages.checks_7d_value', ['checks' => $stats7d['checks'], 'failures' => $stats7d['failures']]) }}</dd>
                    <dt>{{ __('websites.pages.response_7d') }}</dt><dd>{{ __('websites.pages.response_7d_avg', ['avg' => $ms($stats7d['avg'])]) }}@if ($stats7d['p95'] !== null){{ __('websites.pages.response_7d_p95', ['p95' => $ms($stats7d['p95'])]) }}@endif</dd>
                    <dt>{{ __('websites.pages.latest_discovery') }}</dt>
                    <dd>
                        @if ($latestRun)
                            <a class="text-accent" href="{{ route('websites.discovery', $domain) }}">{{ Time::dateTime($latestRun->created_at) }}</a> ·
                            <x-status :status="$runStatus[$latestRun->status] ?? 'unknown'" :label="Lang::has('websites.run_status.'.$latestRun->status) ? __('websites.run_status.'.$latestRun->status) : $latestRun->status" />
                        @else
                            {{ __('websites.never_run') }}
                        @endif
                    </dd>
                </dl>
            </section>

            <div class="grid gap-4">
                <section class="ss-panel" aria-labelledby="incidents-title">
                    <div class="ss-panel-head"><div><h2 id="incidents-title" class="ss-panel-title">{{ __('websites.pages.incidents_title') }}</h2><p class="ss-panel-sub">{{ __('websites.pages.incidents_text') }}</p></div></div>
                    @if ($incidents->isEmpty())
                        <x-empty-state icon="i-check" :title="__('websites.pages.incidents_empty')" />
                    @else
                        <ul class="ss-list">
                            @foreach ($incidents as $incident)
                                <li>
                                    <x-severity :severity="$incident->severity" />
                                    <div class="min-w-0">
                                        <a class="text-accent" href="{{ route('incidents.show', $incident) }}">{{ $incident->title }}</a>
                                        <div class="ss-list-sub">{{ Time::relative($incident->opened_at) }}@if ($incident->failing_pages_count){{ __('websites.pages.pages_affected', ['count' => $incident->failing_pages_count]) }}@endif @if ($incident->parent_id){{ __('websites.pages.grouped_under', ['id' => $incident->parent_id]) }}@endif</div>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>

                <section class="ss-panel" aria-labelledby="changes-title">
                    <div class="ss-panel-head"><div><h2 id="changes-title" class="ss-panel-title">{{ __('websites.pages.changes_title') }}</h2><p class="ss-panel-sub">{{ __('websites.pages.changes_text') }}</p></div></div>
                    @if ($changes->isEmpty())
                        <x-empty-state icon="i-history" :title="__('websites.pages.changes_empty_title')">{{ __('websites.pages.changes_empty_text') }}</x-empty-state>
                    @else
                        <ul class="ss-list">
                            @foreach ($changes as $change)
                                <li>
                                    <span class="ss-chip">{{ __('websites.change_type.'.$change['type']) }}</span>
                                    <div class="min-w-0">
                                        <a class="text-accent [overflow-wrap:anywhere]" href="{{ $change['url'] }}">{{ $change['title'] }}</a>
                                        <div class="ss-list-sub">{{ Time::dateTime($change['at']) }}@if ($change['detail']) · {{ $change['detail'] }}@endif</div>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>
            </div>
        </div>

        <section class="ss-panel">
            <nav class="ss-tabs-scroll mb-4" aria-label="{{ __('websites.pages.filter_label') }}">
                <div class="ss-segmented">
                    @foreach ($tabs as $key => [$label, $count])
                        <a href="{{ route('websites.pages', [$domain, ...array_filter([...request()->only(['q', 'sort', 'direction', 'source']), 'status' => $key])]) }}"
                           @if ($status === $key) aria-current="page" @endif>{{ __('websites.pages.tabs.'.$label) }} <span class="ss-count">{{ $count }}</span></a>
                    @endforeach
                </div>
            </nav>

            <form method="GET" action="{{ route('websites.pages', $domain) }}" class="mb-4 grid gap-3 sm:grid-cols-[minmax(0,1fr)_auto_auto_auto] sm:items-end" role="search">
                @if ($status)<input type="hidden" name="status" value="{{ $status }}">@endif
                <div>
                    <label class="ss-label" for="q">{{ __('websites.pages.search') }}</label>
                    <input id="q" class="ss-input mt-1.5" type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="{{ __('websites.pages.search_placeholder') }}">
                </div>
                <div class="grid grid-cols-2 gap-3 sm:contents">
                    <div>
                        <label class="ss-label" for="source">{{ __('websites.pages.source') }}</label>
                        <select id="source" class="ss-input mt-1.5" name="source">
                            <option value="">{{ __('websites.pages.all_sources') }}</option>
                            @foreach (MonitoredPage::SOURCES as $v => $l)<option value="{{ $v }}" @selected(($filters['source'] ?? null) === $v)>{{ Label::of('page_source', $v, MonitoredPage::SOURCES) }}</option>@endforeach
                        </select>
                    </div>
                    <div>
                        <label class="ss-label" for="sort">{{ __('websites.pages.sort') }}</label>
                        <select id="sort" class="ss-input mt-1.5" name="sort">
                            @foreach (['status', 'url', 'response', 'checked', 'failure', 'changed'] as $v)<option value="{{ $v }}" @selected(($filters['sort'] ?? 'status') === $v)>{{ __('websites.pages.sorts.'.$v) }}</option>@endforeach
                        </select>
                    </div>
                </div>
                <div>
                    <button class="ss-btn ss-btn-secondary" type="submit"><svg class="ico"><use href="#i-search"/></svg> {{ __('websites.pages.filter') }}</button>
                </div>
            </form>

            @if ($pages->isEmpty())
                @if ($counts['total'] + $counts['pending'] + $counts['rejected'] === 0)
                    <x-empty-state icon="i-globe" :title="__('websites.pages.empty_title')">
                        {{ __('websites.pages.empty_text') }}
                        @if ($isAdmin)
                            <x-slot:actions>
                                <a href="{{ route('websites.pages.create', $domain) }}" class="ss-btn ss-btn-primary"><svg class="ico"><use href="#i-plus"/></svg> {{ __('websites.pages.add') }}</a>
                                <a href="{{ route('websites.discovery', $domain) }}" class="ss-btn ss-btn-secondary">{{ __('websites.pages.discover') }}</a>
                            </x-slot:actions>
                        @endif
                    </x-empty-state>
                @else
                    <x-empty-state icon="i-search" :title="__('websites.pages.none_found')">{{ __('websites.pages.none_found_text') }}</x-empty-state>
                @endif
            @else
                <form method="POST" action="{{ route('websites.pages.bulk', $domain) }}" id="bulk-form" data-bulk-form data-delete-confirm="{{ __('websites.pages.bulk_delete_confirm') }}" data-selected-text="{{ __('websites.pages.selected', ['count' => ':count']) }}">
                    @csrf
                    @if ($isAdmin)
                        <div class="ss-bulkbar" data-bulk-bar>
                            <div>
                                <label class="ss-label" for="bulk-action">{{ __('websites.pages.bulk_action') }}</label>
                                <select id="bulk-action" class="ss-input mt-1.5" name="action" required>
                                    <option value="">{{ __('websites.pages.bulk_choose') }}</option>
                                    @foreach (['approve', 'reject', 'pause', 'resume', 'check', 'interval', 'delete'] as $action)
                                        <option value="{{ $action }}">{{ __('websites.pages.bulk.'.$action) }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="ss-label" for="bulk-interval">{{ __('websites.pages.bulk_interval') }}</label>
                                <input id="bulk-interval" class="ss-input mt-1.5 !w-28" type="number" name="interval" min="1" max="1440" placeholder="{{ __('websites.pages.bulk_interval_placeholder') }}">
                            </div>
                            <button class="ss-btn ss-btn-secondary" type="submit" data-bulk-submit>{{ __('websites.pages.bulk_submit') }}</button>
                            <span class="self-center text-xs text-muted" data-selected-count role="status">{{ __('websites.pages.selected', ['count' => 0]) }}</span>
                        </div>
                    @endif

                    <div class="ss-table-wrap">
                        <table class="ss-table ss-table-cards">
                            <thead>
                                <tr>
                                    @if ($isAdmin)<th scope="col"><label class="sr-only" for="select-all">{{ __('websites.pages.select_all') }}</label><input id="select-all" type="checkbox" data-select-all></th>@endif
                                    <th scope="col" class="min-w-52">{!! $sortLink('url', __('websites.pages.col_page')) !!}</th>
                                    <th scope="col">{!! $sortLink('status', __('websites.pages.col_status')) !!}</th>
                                    <th scope="col" class="num">{{ __('websites.pages.col_http') }}</th>
                                    <th scope="col" class="num">{!! $sortLink('response', __('websites.pages.col_response')) !!}</th>
                                    <th scope="col">{!! $sortLink('checked', __('websites.pages.col_checked')) !!}</th>
                                    <th scope="col">{!! $sortLink('failure', __('websites.pages.col_failure')) !!}</th>
                                    <th scope="col">{{ __('websites.pages.col_ssl') }}</th>
                                    <th scope="col">{!! $sortLink('changed', __('websites.pages.col_changed')) !!}</th>
                                    <th scope="col">{{ __('websites.pages.col_next') }}</th>
                                    <th scope="col">{{ __('websites.pages.col_alerts') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($pages as $page)
                                    @php $display = $page->displayStatus(); @endphp
                                    <tr>
                                        @if ($isAdmin)<td class="is-actions"><label class="ss-check"><input id="page-{{ $page->id }}" type="checkbox" name="ids[]" value="{{ $page->id }}" data-row-select><span class="sr-only">{{ __('websites.pages.select', ['url' => $page->url]) }}</span></label></td>@endif
                                        <td class="is-primary">
                                            <a class="font-semibold text-fg no-underline hover:text-accent [overflow-wrap:anywhere]" href="{{ route('pages.show', $page) }}">{{ $page->displayName() }}</a>
                                            <span class="block text-xs text-muted">{{ $page->title ?? '—' }} · {{ Label::of('page_source', $page->source, MonitoredPage::SOURCES) }} · {{ __('websites.pages.every', ['minutes' => $page->check_interval_minutes]) }}</span>
                                        </td>
                                        <td data-label="{{ __('websites.pages.col_status') }}">
                                            <span>
                                                <x-status :status="['pending' => 'queued', 'rejected' => 'closed'][$display] ?? $display" :label="$pageStatusLabel($display)" />
                                                @if ($page->last_failure_type && $page->isMonitored())<span class="block text-xs text-muted">{{ Label::of('page_failure', $page->last_failure_type, MonitoredPage::FAILURE_LABELS) }}@if ($page->consecutive_failures) · {{ $page->consecutive_failures }}×@endif</span>@endif
                                            </span>
                                        </td>
                                        <td data-label="{{ __('websites.pages.col_http') }}" class="num">{{ $page->last_status_code ?? '—' }}</td>
                                        <td data-label="{{ __('websites.pages.col_response') }}" class="num">{{ $ms($page->last_response_ms) }}</td>
                                        <td data-label="{{ __('websites.pages.col_checked') }}" class="whitespace-nowrap">@if ($page->last_checked_at)<span>{{ Time::relative($page->last_checked_at) }}<span class="block text-xs text-muted">{{ __('websites.pages.ok_since', ['time' => Time::relative($page->last_success_at)]) }}</span></span>@else<span class="text-muted">—</span>@endif</td>
                                        <td data-label="{{ __('websites.pages.col_failure') }}" class="whitespace-nowrap">{{ $page->last_failure_at ? Time::relative($page->last_failure_at) : '—' }}</td>
                                        <td data-label="{{ __('websites.pages.col_ssl') }}">@if (! $page->isHttps())<span class="text-xs text-muted">{{ __('websites.not_applicable') }}</span>@elseif ($page->tls_valid === null)<span class="text-xs text-muted">—</span>@else<x-status :status="$page->tls_valid ? 'valid' : 'invalid'" />@endif</td>
                                        <td data-label="{{ __('websites.pages.col_changed') }}" class="whitespace-nowrap text-xs">{{ $page->content_monitoring ? ($page->content_changed_at ? Time::relative($page->content_changed_at) : __('websites.pages.none')) : __('websites.off') }}</td>
                                        <td data-label="{{ __('websites.pages.col_next') }}" class="whitespace-nowrap text-xs">{{ $page->isMonitored() && $page->next_check_at ? Time::format($page->next_check_at, 'H:i') : '—' }}</td>
                                        <td data-label="{{ __('websites.pages.col_alerts') }}" class="text-xs">@if ($page->alerts_enabled)<x-severity :severity="$page->alert_severity" />@else<span class="text-muted">{{ __('websites.off') }}</span>@endif</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </form>
                <x-pagination :paginator="$pages" />
            @endif
        </section>
    </div>
</x-layouts.app>
