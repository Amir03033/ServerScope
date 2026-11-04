@php
    use App\Support\Format;
    use App\Support\Time;

    $isAdmin = auth()->user()->can('admin');
    $periodLabel = __('logs.periods.'.$period);
    $query = fn (array $change) => route('logs.index', array_filter([...$filters, ...$change], fn ($value) => $value !== null && $value !== ''));

    // Activity chart: stacked bars (errors below, warnings on top), scaled to the busiest bucket.
    $max = max(1, ...array_map(fn ($b) => $b['errors'] + $b['warnings'], $histogram));
    $barWidth = 10;
    $chartWidth = count($histogram) * $barWidth;
    $tickFormat = $period === '7d' || ($incident && $from->diffInHours($to) > 24) ? 'd-m H:i' : 'H:i';
    $ticks = collect([0, 0.25, 0.5, 0.75, 1])->map(fn ($f) => $histogram[(int) round($f * (count($histogram) - 1))]['at'])->unique();

    // Trend against the period before (not shown for one incident).
    $trend = null;
    if ($previousErrors !== null) {
        $trend = $previousErrors === 0
            ? ($counts['errors'] > 0 ? ['tone' => 'bad', 'text' => __('logs.stats.new', ['period' => $periodLabel])] : null)
            : (function () use ($counts, $previousErrors, $periodLabel) {
                $change = (int) round(($counts['errors'] - $previousErrors) / $previousErrors * 100);

                return $change === 0
                    ? ['tone' => 'muted', 'text' => __('logs.stats.same', ['period' => $periodLabel])]
                    : ['tone' => $change > 0 ? 'bad' : 'ok', 'text' => ($change > 0 ? '▲ +' : '▼ ').$change.'% '.__('logs.stats.vs_previous', ['period' => $periodLabel])];
            })();
    }
@endphp

<x-layouts.app :title="__('logs.title')">
    <x-slot:pageTitle>{{ __('logs.title') }}</x-slot:pageTitle>
    <x-slot:subtitle>{{ __('logs.subtitle') }}</x-slot:subtitle>
    <x-slot:actions>
        <button type="button" class="ss-btn ss-btn-secondary" popovertarget="logs-setup"><svg class="ico"><use href="#i-sliders"/></svg> {{ __('logs.setup') }}</button>
        @if ($isAdmin && Route::has('alert-rules.create'))
            <a class="ss-btn ss-btn-primary" href="{{ route('alert-rules.create', ['metric' => 'log_errors']) }}"><svg class="ico"><use href="#i-bell"/></svg> {{ __('logs.spike_alert') }}</a>
        @endif
    </x-slot:actions>

    @if (! $hasAny)
        {{-- Nothing received yet: how to start. --}}
        <section class="ss-panel ss-logs-onboarding" aria-labelledby="logs-start">
            <div class="ss-logs-onboarding-intro">
                <span class="ss-empty-icon"><svg class="ico"><use href="#i-logs"/></svg></span>
                <h2 id="logs-start" class="m-0 text-lg font-semibold">{{ __('logs.onboarding.title') }}</h2>
                <p class="m-0 mt-2 max-w-2xl text-sm text-muted">{{ __('logs.onboarding.text') }}</p>
            </div>
            <div class="mx-auto mt-6 max-w-3xl">@include('logs._setup', ['prefix' => 'logs-start'])</div>
        </section>
    @else
        <div class="grid gap-4">
            @if ($incident)
                <div class="ss-alert ss-alert-info" role="status">
                    <svg class="ico"><use href="#i-siren"/></svg>
                    <div class="min-w-0 flex-1">
                        <strong>{{ __('logs.incident_banner', ['id' => $incident->id, 'title' => $incident->title]) }}</strong>
                        <span class="block text-sm">{{ __('logs.incident_window', ['from' => Time::dateTime($from), 'to' => Time::dateTime($to)]) }}</span>
                    </div>
                    <a class="ss-btn ss-btn-secondary ss-btn-sm" href="{{ route('incidents.show', $incident) }}">{{ __('logs.back_to_incident') }}</a>
                </div>
            @endif

            {{-- Key figures --}}
            <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <div @class(['ss-stat', 'is-bad' => $counts['errors'] > 0])>
                    <span class="ss-stat-icon"><svg class="ico"><use href="#i-alert"/></svg></span>
                    <div><div class="ss-stat-label">{{ __('logs.stats.errors') }}</div><div class="ss-stat-value">{{ Format::number($counts['errors']) }}</div>
                        @if ($trend)<div @class(['ss-stat-caption', 'ss-text-bad' => $trend['tone'] === 'bad', 'ss-text-ok' => $trend['tone'] === 'ok'])>{{ $trend['text'] }}</div>@else<div class="ss-stat-caption">{{ $incident ? '' : $periodLabel }}</div>@endif
                    </div>
                </div>
                <div @class(['ss-stat', 'is-warn' => $counts['warnings'] > 0])>
                    <span class="ss-stat-icon"><svg class="ico"><use href="#i-bell"/></svg></span>
                    <div><div class="ss-stat-label">{{ __('logs.stats.warnings') }}</div><div class="ss-stat-value">{{ Format::number($counts['warnings']) }}</div><div class="ss-stat-caption">{{ $incident ? '' : $periodLabel }}</div></div>
                </div>
                <div class="ss-stat">
                    <span class="ss-stat-icon"><svg class="ico"><use href="#i-list"/></svg></span>
                    <div><div class="ss-stat-label">{{ __('logs.stats.issues') }}</div><div class="ss-stat-value">{{ Format::number($counts['issues']) }}</div><div class="ss-stat-caption">{{ __('logs.stats.issues_caption') }}</div></div>
                </div>
                <div class="ss-stat">
                    <span class="ss-stat-icon"><svg class="ico"><use href="#i-server"/></svg></span>
                    <div><div class="ss-stat-label">{{ __('logs.stats.servers') }}</div><div class="ss-stat-value">{{ Format::number($counts['servers']) }}</div><div class="ss-stat-caption">{{ __('logs.stats.servers_caption') }}</div></div>
                </div>
            </div>

            {{-- Activity over time --}}
            <section class="ss-panel" aria-labelledby="logs-activity">
                <div class="ss-panel-head">
                    <div>
                        <h2 id="logs-activity" class="ss-panel-title">{{ __('logs.activity') }}</h2>
                        <p class="ss-panel-sub ss-logchart-legend"><span data-tone="error">{{ __('logs.legend.errors') }}</span><span data-tone="warning">{{ __('logs.legend.warnings') }}</span></p>
                    </div>
                    @unless ($incident)
                        <nav class="ss-segmented" aria-label="{{ __('logs.activity') }}">
                            @foreach (array_keys(\App\Services\Logs\LogQuery::PERIODS) as $p)
                                <a href="{{ $query(['period' => $p]) }}" @if ($p === $period) aria-current="page" @endif>{{ __('logs.periods.'.$p) }}</a>
                            @endforeach
                        </nav>
                    @endunless
                </div>
                <svg class="ss-logchart" viewBox="0 0 {{ $chartWidth }} 100" preserveAspectRatio="none" role="img" aria-label="{{ __('logs.activity') }}">
                    @foreach ($histogram as $i => $b)
                        @php
                            $he = $b['errors'] / $max * 96;
                            $hw = $b['warnings'] / $max * 96;
                        @endphp
                        <g class="ss-logchart-bar">
                            <title>{{ __('logs.bar', ['time' => Time::format($b['at'], $tickFormat), 'errors' => $b['errors'], 'warnings' => $b['warnings']]) }}</title>
                            <rect class="ss-logchart-hit" x="{{ $i * $barWidth }}" y="0" width="{{ $barWidth }}" height="100"/>
                            @if ($he > 0)<rect class="ss-logchart-error" x="{{ $i * $barWidth + 1.5 }}" y="{{ 100 - $he }}" width="{{ $barWidth - 3 }}" height="{{ $he }}" rx="1"/>@endif
                            @if ($hw > 0)<rect class="ss-logchart-warning" x="{{ $i * $barWidth + 1.5 }}" y="{{ 100 - $he - $hw }}" width="{{ $barWidth - 3 }}" height="{{ $hw }}" rx="1"/>@endif
                        </g>
                    @endforeach
                </svg>
                <div class="ss-logchart-axis" aria-hidden="true">
                    @foreach ($ticks as $tick)<span>{{ Time::format($tick, $tickFormat) }}</span>@endforeach
                </div>
            </section>

            {{-- Filters, view switch, and the lines --}}
            <section class="ss-panel" aria-labelledby="logs-lines">
                <h2 id="logs-lines" class="sr-only">{{ __('logs.views.'.$view) }}</h2>
                <form method="GET" action="{{ route('logs.index') }}" data-filters-label="{{ __('common.filters') }}" class="ss-filters mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-[minmax(0,1fr)_auto_auto_auto_auto] lg:items-end" role="search">
                    @foreach (['period', 'view', 'incident', 'fingerprint'] as $keep)
                        @if (! empty($filters[$keep]))<input type="hidden" name="{{ $keep }}" value="{{ $filters[$keep] }}">@endif
                    @endforeach
                    <div class="sm:col-span-2 lg:col-span-1">
                        <label class="ss-label" for="q">{{ __('logs.filters.search') }}</label>
                        <input id="q" class="ss-input mt-1.5" type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="{{ __('logs.filters.search_placeholder') }}">
                    </div>
                    @unless ($incident)
                        <div>
                            <label class="ss-label" for="server">{{ __('logs.filters.server') }}</label>
                            <select id="server" class="ss-input mt-1.5" name="server"><option value="">{{ __('logs.filters.all_servers') }}</option>
                                @foreach ($servers as $s)<option value="{{ $s->id }}" @selected((int) ($filters['server'] ?? 0) === $s->id)>{{ $s->name }}</option>@endforeach
                            </select>
                        </div>
                    @endunless
                    <div>
                        <label class="ss-label" for="source">{{ __('logs.filters.source') }}</label>
                        <select id="source" class="ss-input mt-1.5" name="source"><option value="">{{ __('logs.filters.all_sources') }}</option>
                            @foreach ($sources as $s)<option value="{{ $s }}" @selected(($filters['source'] ?? null) === $s)>{{ $s }}</option>@endforeach
                        </select>
                    </div>
                    <div>
                        <label class="ss-label" for="level">{{ __('logs.filters.level') }}</label>
                        <select id="level" class="ss-input mt-1.5" name="level">
                            <option value="">{{ __('logs.filters.all_levels') }}</option>
                            <option value="errors" @selected(($filters['level'] ?? null) === 'errors')>{{ __('logs.filters.errors') }}</option>
                            <option value="warning" @selected(($filters['level'] ?? null) === 'warning')>{{ __('logs.filters.warning') }}</option>
                        </select>
                    </div>
                    <div class="flex gap-2">
                        <button class="ss-btn ss-btn-secondary" type="submit"><svg class="ico"><use href="#i-search"/></svg> {{ __('logs.filters.apply') }}</button>
                        @if (array_filter(\Illuminate\Support\Arr::only($filters, ['q', 'server', 'source', 'level', 'fingerprint'])))
                            <a class="ss-btn ss-btn-ghost" href="{{ route('logs.index', array_filter(\Illuminate\Support\Arr::only($filters, ['period', 'incident', 'view']))) }}">{{ __('logs.filters.clear') }}</a>
                        @endif
                    </div>
                </form>

                <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                    <nav class="ss-segmented" aria-label="{{ __('logs.title') }}">
                        <a href="{{ $query(['view' => 'issues', 'fingerprint' => null]) }}" @if ($view === 'issues') aria-current="page" @endif><svg class="ico"><use href="#i-list"/></svg> {{ __('logs.views.issues') }} <span class="ss-count">{{ $counts['issues'] }}</span></a>
                        <a href="{{ $query(['view' => 'stream']) }}" @if ($view === 'stream') aria-current="page" @endif><svg class="ico"><use href="#i-logs"/></svg> {{ __('logs.views.stream') }}</a>
                    </nav>
                    @if (! empty($filters['fingerprint']))
                        <p class="m-0 text-sm text-muted">{{ __('logs.only_issue') }} <a href="{{ $query(['fingerprint' => null]) }}">{{ __('logs.show_all') }}</a></p>
                    @endif
                </div>

                @if ($view === 'issues')
                    @if ($issues->isEmpty())
                        <x-empty-state icon="i-check" :title="__('logs.empty_filtered')">{{ __('logs.empty_filtered_text') }}</x-empty-state>
                    @else
                        <ul class="ss-issues" role="list">
                            @foreach ($issues as $issue)
                                @php $spark = $sparklines[$issue['fingerprint']] ?? []; $sparkMax = max(1, ...($spark ?: [0])); @endphp
                                <li>
                                    <a class="ss-issue" data-level="{{ $issue['level'] }}" href="{{ $query(['view' => 'stream', 'fingerprint' => $issue['fingerprint']]) }}" aria-label="{{ __('logs.issue.open') }}: {{ $issue['entry']->message }}">
                                        <span class="ss-issue-main">
                                            <span class="ss-issue-message">{{ $issue['entry']->message }}</span>
                                            <span class="ss-issue-meta">
                                                <span class="ss-loglevel" data-level="{{ $issue['level'] }}">{{ __('logs.levels.'.$issue['level']) }}</span>
                                                <span class="ss-chip">{{ $issue['entry']->source }}</span>
                                                <span>{{ $issue['servers'] > 1 ? __('logs.issue.servers', ['count' => $issue['servers']]) : $issue['entry']->server?->name }}</span>
                                                <span aria-hidden="true">·</span>
                                                <span>{{ __('logs.issue.last', ['time' => Time::relative($issue['last'])]) }}</span>
                                                <span class="hidden sm:inline">· {{ __('logs.issue.first', ['time' => Time::relative($issue['first'])]) }}</span>
                                            </span>
                                        </span>
                                        <span class="ss-issue-side">
                                            @if (count($spark) > 1)
                                                <svg class="ss-spark" viewBox="0 0 {{ count($spark) * 4 }} 24" preserveAspectRatio="none" aria-hidden="true">
                                                    @foreach ($spark as $j => $c)@if ($c > 0)<rect x="{{ $j * 4 + .5 }}" y="{{ 24 - max(2, $c / $sparkMax * 24) }}" width="3" height="{{ max(2, $c / $sparkMax * 24) }}" rx=".5"/>@endif @endforeach
                                                </svg>
                                            @endif
                                            <span class="ss-issue-count">{{ __('logs.issue.times', ['count' => Format::number($issue['count'])]) }}</span>
                                        </span>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                @else
                    @if ($stream->isEmpty())
                        <x-empty-state icon="i-check" :title="__('logs.empty_filtered')">{{ __('logs.empty_filtered_text') }}</x-empty-state>
                    @else
                        <ul @class(['ss-logstream', 'has-dates' => $period === '7d' || $incident]) role="list">
                            @foreach ($stream as $entry)
                                <li>
                                    <details class="ss-logline" data-level="{{ $entry->level }}">
                                        <summary>
                                            <time datetime="{{ $entry->occurred_at->toIso8601String() }}" title="{{ Time::dateTimeSeconds($entry->occurred_at) }}">{{ Time::format($entry->occurred_at, $period === '7d' || $incident ? 'd-m H:i:s' : 'H:i:s') }}</time>
                                            <span class="ss-loglevel" data-level="{{ $entry->level }}">{{ __('logs.levels.'.$entry->level) }}</span>
                                            <span class="ss-logline-where">{{ $entry->source }} · {{ $entry->server?->name }}</span>
                                            <span class="ss-logline-message">{{ $entry->message }}</span>
                                        </summary>
                                        <div class="ss-logline-body">
                                            <x-terminal :id="'log-'.$entry->id" :label="$entry->source.' · '.$entry->server?->name">{{ $entry->message }}{{ $entry->context ? "\n".$entry->context : '' }}</x-terminal>
                                            @unless ($entry->context)<p class="m-0 mt-2 text-xs text-subtle">{{ __('logs.stream.no_context') }}</p>@endunless
                                            @if (($filters['fingerprint'] ?? null) !== $entry->fingerprint)
                                                <a class="ss-btn ss-btn-ghost ss-btn-sm mt-2" href="{{ $query(['view' => 'stream', 'fingerprint' => $entry->fingerprint]) }}">{{ __('logs.stream.similar') }}</a>
                                            @endif
                                        </div>
                                    </details>
                                </li>
                            @endforeach
                        </ul>
                        <x-pagination :paginator="$stream" />
                    @endif
                @endif
            </section>
        </div>
    @endif

    <div id="logs-setup" popover class="ss-sheet ss-sheet-lg" role="dialog" aria-labelledby="logs-setup-title">
        <div class="grid gap-4 p-5">
            <div class="flex items-start gap-3">
                <span class="ss-sheet-icon"><svg class="ico h-[18px] w-[18px]"><use href="#i-logs"/></svg></span>
                <div class="min-w-0">
                    <h2 id="logs-setup-title" class="m-0 text-base font-semibold text-fg">{{ __('logs.onboarding.title') }}</h2>
                    <p class="m-0 mt-1 text-sm text-muted">{{ __('logs.onboarding.text') }}</p>
                </div>
            </div>
            @include('logs._setup', ['prefix' => 'logs-setup'])
            <div class="flex justify-end"><button type="button" class="ss-btn ss-btn-secondary" popovertarget="logs-setup" popovertargetaction="hide">{{ __('common.close') }}</button></div>
        </div>
    </div>
</x-layouts.app>
