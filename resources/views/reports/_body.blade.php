@php
    use App\Models\Incident;
    use App\Services\Incidents\IncidentMetrics;
    use App\Support\Format;
    use App\Support\Label;
    use App\Support\Severity;

    $pct = fn (?float $v, int $d = 3) => Format::percent($v, $d);
    $ms = fn (?float $v) => $v === null ? '—' : Format::number($v).' ms';
    $minutes = fn (?int $v) => $v === null ? '—' : __('reports.minutes', ['count' => $v]);
    $c = $report['coverage'];
@endphp

<div class="grid gap-4">
    <section class="grid grid-cols-2 gap-3 lg:grid-cols-5" aria-label="{{ __('reports.stats_label') }}">
        <article class="ss-stat col-span-2 lg:col-span-1">
            <span class="ss-stat-icon"><svg class="ico"><use href="#i-check"/></svg></span>
            <div class="min-w-0"><div class="ss-stat-label">{{ __('reports.average_uptime') }}</div><div class="ss-stat-value">{{ $pct($report['average_uptime']) }}</div><div class="ss-stat-caption">{{ __('reports.target_caption', ['target' => $pct($report['target'], 2)]) }}</div></div>
        </article>
        <article @class(['ss-stat', 'is-bad' => ($report['incidents']['by_severity']['critical'] ?? 0) > 0])>
            <span class="ss-stat-icon"><svg class="ico"><use href="#i-siren"/></svg></span>
            <div class="min-w-0"><div class="ss-stat-label">{{ __('reports.incidents') }}</div><div class="ss-stat-value">{{ $report['incidents']['opened'] }}</div><div class="ss-stat-caption">{{ __('reports.incidents_caption', ['critical' => $report['incidents']['by_severity']['critical'] ?? 0, 'duration' => IncidentMetrics::humanDuration($report['incident_duration_seconds'])]) }}</div></div>
        </article>
        <article class="ss-stat">
            <span class="ss-stat-icon"><svg class="ico"><use href="#i-search"/></svg></span>
            <div class="min-w-0"><div class="ss-stat-label">{{ __('reports.mttd') }}</div><div class="ss-stat-value">{{ IncidentMetrics::humanDuration($report['incidents']['mttd_seconds']) }}</div><div class="ss-stat-caption">{{ __('reports.mttd_caption') }}</div></div>
        </article>
        <article class="ss-stat">
            <span class="ss-stat-icon"><svg class="ico"><use href="#i-clock"/></svg></span>
            <div class="min-w-0"><div class="ss-stat-label">{{ __('reports.mtta') }}</div><div class="ss-stat-value">{{ IncidentMetrics::humanDuration($report['incidents']['mtta_seconds']) }}</div></div>
        </article>
        <article class="ss-stat">
            <span class="ss-stat-icon"><svg class="ico"><use href="#i-wrench"/></svg></span>
            <div class="min-w-0"><div class="ss-stat-label">{{ __('reports.mttr') }}</div><div class="ss-stat-value">{{ IncidentMetrics::humanDuration($report['incidents']['mttr_seconds']) }}</div></div>
        </article>
    </section>

    <section class="ss-panel ss-report-section" aria-labelledby="report-domains">
        <div class="ss-panel-head"><div><h2 id="report-domains" class="ss-panel-title">{{ __('reports.domains_title') }}</h2><p class="ss-panel-sub">{{ __('reports.domains_text') }}</p></div></div>
        @if ($report['domains']->isEmpty())
            <x-empty-state icon="i-globe" :title="__('reports.domains_empty')" />
        @else
            <div class="ss-table-wrap">
                <table class="ss-table ss-table-cards">
                    <thead><tr><th scope="col">{{ __('reports.col_domain') }}</th><th scope="col" class="num">{{ __('reports.col_uptime') }}</th><th scope="col" class="num">{{ __('reports.col_coverage') }}</th><th scope="col" class="num">{{ __('reports.col_downtime') }}</th><th scope="col" class="num">{{ __('reports.col_avg_response') }}</th><th scope="col" class="num">{{ __('reports.col_max') }}</th><th scope="col" class="num">{{ __('reports.col_incidents') }}</th><th scope="col">{{ __('reports.col_target') }}</th></tr></thead>
                    <tbody>
                        @foreach ($report['domains'] as $row)
                            <tr>
                                <td class="is-primary"><a class="font-semibold text-fg no-underline hover:text-accent" href="{{ route('domains.show', $row['domain']) }}">{{ $row['domain']->displayName() }}</a><span class="block text-xs text-muted">{{ __('reports.checks', ['count' => $row['checks']]) }}</span></td>
                                <td data-label="{{ __('reports.col_uptime') }}" class="num">{{ $pct($row['uptime']) }}</td>
                                <td data-label="{{ __('reports.col_coverage') }}" class="num">{{ $pct($row['coverage'], 1) }}</td>
                                <td data-label="{{ __('reports.col_downtime') }}" class="num">{{ $minutes($row['downtime_minutes']) }}</td>
                                <td data-label="{{ __('reports.col_avg_response') }}" class="num">{{ $ms($row['avg_ms']) }}</td>
                                <td data-label="{{ __('reports.col_max') }}" class="num">{{ $ms($row['max_ms']) }}</td>
                                <td data-label="{{ __('reports.col_incidents') }}" class="num">{{ $row['incidents'] }}</td>
                                <td data-label="{{ __('reports.col_target') }}">@include('reports._target', ['row' => $row])</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <section class="ss-panel ss-report-section" aria-labelledby="report-servers">
        <div class="ss-panel-head"><div><h2 id="report-servers" class="ss-panel-title">{{ __('reports.servers_title') }}</h2><p class="ss-panel-sub">{{ __('reports.servers_text') }}</p></div></div>
        @if ($report['servers']->isEmpty())
            <x-empty-state icon="i-server" :title="__('reports.servers_empty')" />
        @else
            <div class="ss-table-wrap">
                <table class="ss-table ss-table-cards">
                    <thead><tr><th scope="col">{{ __('reports.col_server') }}</th><th scope="col" class="num">{{ __('reports.col_uptime') }}</th><th scope="col" class="num">{{ __('reports.col_coverage') }}</th><th scope="col" class="num">{{ __('reports.col_downtime') }}</th><th scope="col" class="num">{{ __('reports.col_samples') }}</th><th scope="col" class="num">{{ __('reports.col_incidents') }}</th><th scope="col">{{ __('reports.col_target') }}</th></tr></thead>
                    <tbody>
                        @foreach ($report['servers'] as $row)
                            <tr>
                                <td class="is-primary"><a class="font-semibold text-fg no-underline hover:text-accent" href="{{ route('servers.show', $row['server']) }}">{{ $row['server']->name }}</a>@if ($row['server']->trashed())<span class="block text-xs text-muted">{{ __('reports.deleted') }}</span>@endif</td>
                                <td data-label="{{ __('reports.col_uptime') }}" class="num">{{ $pct($row['uptime']) }}</td>
                                <td data-label="{{ __('reports.col_coverage') }}" class="num">{{ $pct($row['coverage'], 1) }}</td>
                                <td data-label="{{ __('reports.col_downtime') }}" class="num">{{ __('reports.minutes', ['count' => $row['downtime_minutes']]) }}</td>
                                <td data-label="{{ __('reports.col_samples') }}" class="num">{{ Format::number($row['samples']) }}</td>
                                <td data-label="{{ __('reports.col_incidents') }}" class="num">{{ $row['incidents'] }}</td>
                                <td data-label="{{ __('reports.col_target') }}">@include('reports._target', ['row' => $row])</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <section class="ss-panel ss-report-section" aria-labelledby="report-pages">
        <div class="ss-panel-head"><div><h2 id="report-pages" class="ss-panel-title">{{ __('reports.pages_title') }}</h2><p class="ss-panel-sub">{{ __('reports.pages_text') }}</p></div></div>
        @if ($report['pages']->isEmpty())
            <x-empty-state icon="i-list" :title="__('reports.pages_empty')" />
        @else
            <div class="ss-table-wrap">
                <table class="ss-table ss-table-cards">
                    <thead><tr><th scope="col">{{ __('reports.col_page') }}</th><th scope="col" class="num">{{ __('reports.col_uptime') }}</th><th scope="col" class="num">{{ __('reports.col_coverage') }}</th><th scope="col" class="num">{{ __('reports.col_downtime') }}</th><th scope="col" class="num">{{ __('reports.col_avg') }}</th><th scope="col" class="num">{{ __('reports.col_p95') }}</th><th scope="col" class="num">{{ __('reports.col_incidents') }}</th><th scope="col">{{ __('reports.col_target') }}</th></tr></thead>
                    <tbody>
                        @foreach ($report['pages'] as $row)
                            <tr>
                                <td class="is-primary"><a class="font-semibold text-fg no-underline hover:text-accent [overflow-wrap:anywhere]" href="{{ route('pages.show', $row['page']) }}">{{ $row['page']->url }}</a><span class="block text-xs text-muted">{{ __('reports.checks', ['count' => $row['checks']]) }}{{ $row['page']->is_active ? '' : __('reports.paused') }}</span></td>
                                <td data-label="{{ __('reports.col_uptime') }}" class="num">{{ $pct($row['uptime']) }}</td>
                                <td data-label="{{ __('reports.col_coverage') }}" class="num">{{ $pct($row['coverage'], 1) }}</td>
                                <td data-label="{{ __('reports.col_downtime') }}" class="num">{{ $minutes($row['downtime_minutes']) }}</td>
                                <td data-label="{{ __('reports.col_avg') }}" class="num">{{ $ms($row['avg_ms']) }}</td>
                                <td data-label="{{ __('reports.col_p95') }}" class="num">{{ $ms($row['p95_ms']) }}</td>
                                <td data-label="{{ __('reports.col_incidents') }}" class="num">{{ $row['incidents'] }}</td>
                                <td data-label="{{ __('reports.col_target') }}">@include('reports._target', ['row' => $row])</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <div class="grid gap-4 lg:grid-cols-2">
        <section class="ss-panel ss-report-section" aria-labelledby="report-coverage">
            <div class="ss-panel-head"><div><h2 id="report-coverage" class="ss-panel-title">{{ __('reports.coverage_title') }}</h2><p class="ss-panel-sub">{{ __('reports.coverage_text') }}</p></div></div>
            <dl class="ss-dl">
                <dt>{{ __('reports.servers_reporting') }}</dt><dd>{{ __('reports.of', ['count' => $c['servers_reporting'], 'total' => $c['servers']]) }} @if ($c['servers_low_coverage'])<span class="ss-text-warn">{{ __('reports.low_coverage', ['count' => $c['servers_low_coverage']]) }}</span>@endif</dd>
                <dt>{{ __('reports.servers_services') }}</dt><dd>{{ __('reports.services_value', ['count' => $c['servers_with_services'], 'services' => $c['services']]) }}</dd>
                <dt>{{ __('reports.domains_checked') }}</dt><dd>{{ __('reports.of', ['count' => $c['domains_checked'], 'total' => $c['domains']]) }} @if ($c['domains_low_coverage'])<span class="ss-text-warn">{{ __('reports.low_coverage', ['count' => $c['domains_low_coverage']]) }}</span>@endif</dd>
                <dt>{{ __('reports.pages_checked') }}</dt><dd>{{ __('reports.of', ['count' => $c['pages_checked'], 'total' => $c['pages']]) }} @if ($c['pages_low_coverage'])<span class="ss-text-warn">{{ __('reports.low_coverage', ['count' => $c['pages_low_coverage']]) }}</span>@endif</dd>
                {{-- Translation text from lang/ with integer parameters: printed as is (the apostrophe stays literal). --}}
                <dt>{{ __('reports.not_monitored') }}</dt><dd>{!! __('reports.not_monitored_value', ['pending' => (int) $c['pages_pending'], 'paused' => (int) $c['pages_paused']]) !!}</dd>
            </dl>
        </section>

        <section class="ss-panel ss-report-section" aria-labelledby="report-recurring">
            <div class="ss-panel-head"><div><h2 id="report-recurring" class="ss-panel-title">{{ __('reports.recurring_title') }}</h2><p class="ss-panel-sub">{{ __('reports.recurring_text') }}</p></div></div>
            @if ($report['recurring']->isEmpty())
                <x-empty-state icon="i-check" :title="__('reports.recurring_empty')" />
            @else
                <ul class="ss-list">
                    @foreach ($report['recurring'] as $row)
                        <li><div class="min-w-0"><a class="text-accent" href="{{ route('incidents.show', $row['latest_id']) }}">{{ $row['title'] }}</a><div class="ss-list-sub">{{ __('reports.recurring_value', ['count' => $row['occurrences'], 'duration' => IncidentMetrics::humanDuration($row['total_seconds'])]) }}</div></div></li>
                    @endforeach
                </ul>
            @endif
        </section>
    </div>

    <section class="ss-panel ss-report-section" aria-labelledby="report-by">
        <div class="ss-panel-head"><div><h2 id="report-by" class="ss-panel-title">{{ __('reports.by_title') }}</h2></div></div>
        <div class="grid gap-6 lg:grid-cols-2">
            <dl class="ss-dl" aria-label="{{ __('reports.by_severity') }}">@foreach (Severity::options() as $key => $label)<dt>{{ Label::of('severity', $key, Severity::LABELS) }}</dt><dd>{{ $report['incidents']['by_severity'][$key] ?? 0 }}</dd>@endforeach</dl>
            <dl class="ss-dl" aria-label="{{ __('reports.by_source') }}">@forelse ($report['incidents']['by_source'] as $source => $count)<dt>{{ Label::of('incident_source', $source, Incident::SOURCE_LABELS) }}</dt><dd>{{ $count }}</dd>@empty<dt>—</dt><dd>{{ __('reports.no_incidents') }}</dd>@endforelse</dl>
        </div>
    </section>
</div>
