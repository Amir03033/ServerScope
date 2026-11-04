@php
    use App\Support\Format;
    use App\Support\Time;

    $isAdmin = auth()->user()->can('admin');
    $periodLabel = __('logs.periods.'.$period);
    $query = fn (array $change) => route('logs.index', array_filter([...$filters, ...$change], fn ($value) => $value !== null && $value !== ''));
    $filtered = array_filter(\Illuminate\Support\Arr::only($filters, ['q', 'server', 'source', 'level', 'fingerprint'])) !== [];

    // Activity chart: stacked bars (errors below, warnings on top), scaled to a round maximum.
    $peak = max(0, ...array_map(fn ($b) => $b['errors'] + $b['warnings'], $histogram));
    $step = $peak <= 4 ? 1 : 10 ** max(0, (int) floor(log10($peak / 4)));
    $scale = max(4, (int) (ceil($peak / 4 / $step) * $step * 4));
    $barWidth = 10;
    $chartWidth = count($histogram) * $barWidth;
    $tickFormat = $period === '7d' || ($incident && $from->diffInHours($to) > 24) ? 'd M H:i' : 'H:i';
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
                    : ['tone' => $change > 0 ? 'bad' : 'ok', 'text' => ($change > 0 ? '+' : '').$change.'% '.__('logs.stats.vs_previous', ['period' => $periodLabel])];
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
                <div class="ss-lx-context" role="status">
                    <span class="ss-lx-context-icon"><svg class="ico" aria-hidden="true"><use href="#i-siren"/></svg></span>
                    <div class="min-w-0 flex-1">
                        <p class="m-0 text-sm font-semibold">{{ __('logs.incident_banner', ['id' => $incident->id, 'title' => $incident->title]) }}</p>
                        <p class="m-0 text-xs text-muted">{{ __('logs.incident_window', ['from' => Time::dateTime($from), 'to' => Time::dateTime($to)]) }}</p>
                    </div>
                    <a class="ss-btn ss-btn-secondary ss-btn-sm" href="{{ route('incidents.show', $incident) }}"><svg class="ico"><use href="#i-arrow-left"/></svg> {{ __('logs.back_to_incident') }}</a>
                </div>
            @endif

            {{-- Overview: key figures and the activity over time in one card. --}}
            <section class="ss-panel ss-lx-overview" aria-labelledby="logs-activity">
                <div class="ss-lx-overview-head">
                    <h2 id="logs-activity" class="ss-panel-title">{{ __('logs.activity') }}</h2>
                    @unless ($incident)
                        <nav class="ss-segmented" aria-label="{{ __('logs.activity') }}">
                            @foreach (array_keys(\App\Services\Logs\LogQuery::PERIODS) as $p)
                                <a href="{{ $query(['period' => $p]) }}" @if ($p === $period) aria-current="page" @endif>{{ __('logs.periods.'.$p) }}</a>
                            @endforeach
                        </nav>
                    @endunless
                </div>

                <dl class="ss-lx-metrics">
                    <div data-tone="error">
                        <dt><span class="ss-lx-swatch" data-tone="error" aria-hidden="true"></span>{{ __('logs.stats.errors') }}</dt>
                        <dd>{{ Format::number($counts['errors']) }}</dd>
                        @if ($trend)<dd @class(['ss-lx-trend', 'is-bad' => $trend['tone'] === 'bad', 'is-ok' => $trend['tone'] === 'ok'])>{{ $trend['text'] }}</dd>@endif
                    </div>
                    <div data-tone="warning">
                        <dt><span class="ss-lx-swatch" data-tone="warning" aria-hidden="true"></span>{{ __('logs.stats.warnings') }}</dt>
                        <dd>{{ Format::number($counts['warnings']) }}</dd>
                    </div>
                    <div>
                        <dt>{{ __('logs.stats.issues') }}</dt>
                        <dd>{{ Format::number($counts['issues']) }}</dd>
                        <dd class="ss-lx-trend">{{ __('logs.stats.issues_caption') }}</dd>
                    </div>
                    <div>
                        <dt>{{ __('logs.stats.servers') }}</dt>
                        <dd>{{ Format::number($counts['servers']) }}</dd>
                        <dd class="ss-lx-trend">{{ __('logs.stats.servers_caption') }}</dd>
                    </div>
                </dl>

                <div class="ss-lx-chart">
                    <div class="ss-lx-yaxis" aria-hidden="true"><span>{{ Format::number($scale) }}</span><span>{{ Format::number($scale / 2) }}</span><span>0</span></div>
                    <div class="ss-lx-plot">
                        <svg class="ss-logchart" viewBox="0 0 {{ $chartWidth }} 100" preserveAspectRatio="none" role="img" aria-label="{{ __('logs.chart_label', ['errors' => $counts['errors'], 'warnings' => $counts['warnings']]) }}">
                            <line class="ss-logchart-grid" x1="0" x2="{{ $chartWidth }}" y1="50" y2="50"/>
                            <line class="ss-logchart-grid" x1="0" x2="{{ $chartWidth }}" y1="0.5" y2="0.5"/>
                            @foreach ($histogram as $i => $b)
                                @php
                                    // A bucket with lines always gets a visible bar, however busy the busiest one was.
                                    $he = $b['errors'] > 0 ? max(1.5, $b['errors'] / $scale * 100) : 0;
                                    $hw = $b['warnings'] > 0 ? max(1.5, $b['warnings'] / $scale * 100) : 0;
                                @endphp
                                <g class="ss-logchart-bar">
                                    <title>{{ __('logs.bar', ['time' => Time::format($b['at'], $tickFormat), 'errors' => $b['errors'], 'warnings' => $b['warnings']]) }}</title>
                                    <rect class="ss-logchart-hit" x="{{ $i * $barWidth }}" y="0" width="{{ $barWidth }}" height="100"/>
                                    @if ($he > 0)<rect class="ss-logchart-error" x="{{ $i * $barWidth + 2 }}" y="{{ 100 - $he }}" width="{{ $barWidth - 4 }}" height="{{ $he }}"/>@endif
                                    @if ($hw > 0)<rect class="ss-logchart-warning" x="{{ $i * $barWidth + 2 }}" y="{{ 100 - $he - $hw }}" width="{{ $barWidth - 4 }}" height="{{ $hw }}"/>@endif
                                </g>
                            @endforeach
                        </svg>
                        <div class="ss-logchart-axis" aria-hidden="true">
                            @foreach ($ticks as $tick)<span>{{ Time::format($tick, $tickFormat) }}</span>@endforeach
                        </div>
                    </div>
                </div>
            </section>

            {{-- The lines: grouped problems or the raw stream, with filters. --}}
            <section class="ss-panel ss-lx-panel" aria-labelledby="logs-lines">
                <h2 id="logs-lines" class="sr-only">{{ __('logs.views.'.$view) }}</h2>
                <div class="ss-lx-toolbar">
                    <nav class="ss-lx-tabs" aria-label="{{ __('logs.title') }}">
                        <a href="{{ $query(['view' => 'issues', 'fingerprint' => null]) }}" @if ($view === 'issues') aria-current="page" @endif>{{ __('logs.views.issues') }} <span class="ss-count">{{ $counts['issues'] }}</span></a>
                        <a href="{{ $query(['view' => 'stream']) }}" @if ($view === 'stream') aria-current="page" @endif>{{ __('logs.views.stream') }}</a>
                    </nav>

                    <form method="GET" action="{{ route('logs.index') }}" class="ss-lx-filters ss-filters" data-filters-label="{{ __('common.filters') }}" role="search">
                        @foreach (['period', 'view', 'incident', 'fingerprint'] as $keep)
                            @if (! empty($filters[$keep]))<input type="hidden" name="{{ $keep }}" value="{{ $filters[$keep] }}">@endif
                        @endforeach
                        <label class="ss-lx-search">
                            <span class="sr-only">{{ __('logs.filters.search') }}</span>
                            <svg class="ico" aria-hidden="true"><use href="#i-search"/></svg>
                            <input type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="{{ __('logs.filters.search_placeholder') }}">
                        </label>
                        @unless ($incident)
                            <label class="ss-lx-select">
                                <span class="sr-only">{{ __('logs.filters.server') }}</span>
                                <select name="server" data-autosubmit><option value="">{{ __('logs.filters.all_servers') }}</option>
                                    @foreach ($servers as $s)<option value="{{ $s->id }}" @selected((int) ($filters['server'] ?? 0) === $s->id)>{{ $s->name }}</option>@endforeach
                                </select>
                            </label>
                        @endunless
                        <label class="ss-lx-select">
                            <span class="sr-only">{{ __('logs.filters.source') }}</span>
                            <select name="source" data-autosubmit><option value="">{{ __('logs.filters.all_sources') }}</option>
                                @foreach ($sources as $s)<option value="{{ $s }}" @selected(($filters['source'] ?? null) === $s)>{{ $s }}</option>@endforeach
                            </select>
                        </label>
                        <label class="ss-lx-select">
                            <span class="sr-only">{{ __('logs.filters.level') }}</span>
                            <select name="level" data-autosubmit>
                                <option value="">{{ __('logs.filters.all_levels') }}</option>
                                <option value="errors" @selected(($filters['level'] ?? null) === 'errors')>{{ __('logs.filters.errors') }}</option>
                                <option value="warning" @selected(($filters['level'] ?? null) === 'warning')>{{ __('logs.filters.warning') }}</option>
                            </select>
                        </label>
                        <button class="ss-btn ss-btn-secondary ss-lx-apply" type="submit">{{ __('logs.filters.apply') }}</button>
                    </form>
                </div>

                @if ($filtered)
                    <div class="ss-lx-active">
                        @if (! empty($filters['fingerprint']))<span>{{ __('logs.only_issue') }}</span>@endif
                        <a href="{{ route('logs.index', array_filter(\Illuminate\Support\Arr::only($filters, ['period', 'incident', 'view']))) }}"><svg class="ico" aria-hidden="true"><use href="#i-x"/></svg> {{ __('logs.filters.clear') }}</a>
                    </div>
                @endif

                @if ($view === 'issues')
                    @if ($issues->isEmpty())
                        <x-empty-state icon="i-check" :title="__('logs.empty_filtered')">{{ __('logs.empty_filtered_text') }}</x-empty-state>
                    @else
                        <div class="ss-lx-issues-head" aria-hidden="true">
                            <span>{{ __('logs.columns.problem') }}</span><span>{{ __('logs.columns.trend', ['period' => $incident ? '' : $periodLabel]) }}</span><span>{{ __('logs.columns.events') }}</span><span>{{ __('logs.columns.last') }}</span>
                        </div>
                        <ul class="ss-issues" role="list">
                            @foreach ($issues as $issue)
                                @php $spark = $sparklines[$issue['fingerprint']] ?? []; $sparkMax = max(1, ...($spark ?: [0])); @endphp
                                <li>
                                    <a class="ss-issue" data-level="{{ $issue['level'] }}" href="{{ $query(['view' => 'stream', 'fingerprint' => $issue['fingerprint']]) }}" aria-label="{{ __('logs.issue.open') }}: {{ $issue['entry']->message }}">
                                        <span class="ss-issue-main">
                                            <span class="ss-issue-message">{{ $issue['entry']->message }}</span>
                                            <span class="ss-issue-meta">
                                                <span class="ss-loglevel" data-level="{{ $issue['level'] }}">{{ __('logs.levels.'.$issue['level']) }}</span>
                                                <span class="ss-issue-source">{{ $issue['entry']->source }}</span>
                                                <span>{{ $issue['servers'] > 1 ? __('logs.issue.servers', ['count' => $issue['servers']]) : $issue['entry']->server?->name }}</span>
                                                <span>{{ __('logs.issue.first', ['time' => Time::relative($issue['first'])]) }}</span>
                                            </span>
                                        </span>
                                        <span class="ss-issue-trend">
                                            @if (count($spark) > 1)
                                                <svg class="ss-spark" viewBox="0 0 {{ count($spark) * 4 }} 24" preserveAspectRatio="none" aria-hidden="true">
                                                    @foreach ($spark as $j => $c)@if ($c > 0)<rect x="{{ $j * 4 + .5 }}" y="{{ 24 - max(2, $c / $sparkMax * 24) }}" width="3" height="{{ max(2, $c / $sparkMax * 24) }}" rx=".5"/>@endif @endforeach
                                                </svg>
                                            @endif
                                        </span>
                                        <span class="ss-issue-count">{{ __('logs.issue.times', ['count' => Format::number($issue['count'])]) }}</span>
                                        <span class="ss-issue-last">{{ Time::relative($issue['last']) }}</span>
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
                                            <time datetime="{{ $entry->occurred_at->toIso8601String() }}" title="{{ Time::dateTimeSeconds($entry->occurred_at) }}">{{ Time::format($entry->occurred_at, $period === '7d' || $incident ? 'd M H:i:s' : 'H:i:s') }}</time>
                                            <span class="ss-loglevel" data-level="{{ $entry->level }}">{{ __('logs.levels.'.$entry->level) }}</span>
                                            <span class="ss-logline-where">{{ $entry->source }} · {{ $entry->server?->name }}</span>
                                            <span class="ss-logline-message">{{ $entry->message }}</span>
                                        </summary>
                                        <div class="ss-logline-body">
                                            <x-terminal :id="'log-'.$entry->id" :label="$entry->source.' · '.$entry->server?->name">{{ $entry->message }}{{ $entry->context ? "\n".$entry->context : '' }}</x-terminal>
                                            <div class="ss-logline-actions">
                                                @unless ($entry->context)<span class="text-xs text-subtle">{{ __('logs.stream.no_context') }}</span>@endunless
                                                @if (($filters['fingerprint'] ?? null) !== $entry->fingerprint)
                                                    <a class="ss-btn ss-btn-ghost ss-btn-sm" href="{{ $query(['view' => 'stream', 'fingerprint' => $entry->fingerprint]) }}">{{ __('logs.stream.similar') }}</a>
                                                @endif
                                            </div>
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
