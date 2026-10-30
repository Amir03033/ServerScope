@php
    use App\Http\Requests\PageRequest;
    use App\Models\MonitoredPage;
    use App\Support\Format;
    use App\Support\Label;
    use App\Support\Time;

    $ms = fn (?float $v) => $v === null ? '—' : Format::number($v).' ms';
    $pct = fn (?float $v, int $d = 2) => Format::percent($v, $d);
    $display = $page->displayStatus();
    $isAdmin = auth()->user()->can('admin');
    $source = Label::of('page_source', $page->source, MonitoredPage::SOURCES);
    $failure = fn (?string $type) => Label::of('page_failure', $type, MonitoredPage::FAILURE_LABELS);
    $statusLabel = in_array($display, ['pending', 'rejected', 'paused'], true) && ! array_key_exists($display, MonitoredPage::STATUS_LABELS)
        ? __('websites.'.$display)
        : Label::of('page_status', $display, MonitoredPage::STATUS_LABELS);
    $statTone = ['up' => 'is-ok', 'degraded' => 'is-warn', 'down' => 'is-bad'][$display] ?? null;

    // Average per 30-minute bucket for the chart; failures flagged.
    $buckets = [];
    $start = now()->subDay();
    for ($i = 0; $i < 48; $i++) { $buckets[$i] = ['sum' => 0, 'n' => 0, 'fail' => false]; }
    foreach ($series as $check) {
        $i = min(47, max(0, (int) floor($start->diffInSeconds($check->checked_at, false) / 1800)));
        if ($check->success && $check->response_time_ms !== null) { $buckets[$i]['sum'] += $check->response_time_ms; $buckets[$i]['n']++; }
        if (! $check->success) { $buckets[$i]['fail'] = true; }
    }
    $chartPoints = array_map(fn ($b, $i) => ['label' => Time::format($start->copy()->addMinutes($i * 30), 'H:i'), 'value' => $b['n'] ? round($b['sum'] / $b['n'], 1) : null, 'flag' => $b['fail']], $buckets, array_keys($buckets));
    $allIncidents = $incidents->concat($groupIncidents)->unique('id')->sortByDesc('opened_at');
@endphp

<x-layouts.app :title="$page->displayName().' — '.$domain->displayName()">
    <x-slot:breadcrumb><span>›</span> <a href="{{ route('domains.show', $domain) }}">{{ $domain->displayName() }}</a> <span>›</span> <a href="{{ route('websites.pages', $domain) }}">{{ __('websites.pages_crumb') }}</a> <span>›</span> {{ $page->displayName() }}</x-slot:breadcrumb>
    <x-slot:pageTitle>{{ $page->title ?: $page->displayName() }}</x-slot:pageTitle>
    <x-slot:subtitle><a href="{{ $page->url }}" target="_blank" rel="noopener noreferrer nofollow" class="text-accent [overflow-wrap:anywhere]">{{ $page->url }}</a></x-slot:subtitle>
    <x-slot:actions>
        <a href="{{ route('websites.pages', $domain) }}" class="ss-btn ss-btn-ghost"><svg class="ico"><use href="#i-arrow-left"/></svg> {{ __('websites.page.pages') }}</a>
        @if ($isAdmin)
            @if ($page->isMonitored())
                <form method="POST" action="{{ route('pages.check', $page) }}">@csrf<button class="ss-btn ss-btn-secondary" type="submit"><svg class="ico"><use href="#i-refresh"/></svg> {{ __('websites.page.check_now') }}</button></form>
                <form method="POST" action="{{ route('pages.pause', $page) }}">@csrf<button class="ss-btn ss-btn-secondary" type="submit"><svg class="ico"><use href="#i-pause"/></svg> {{ __('websites.page.pause') }}</button></form>
            @elseif ($page->approval_status === 'approved')
                <form method="POST" action="{{ route('pages.resume', $page) }}">@csrf<button class="ss-btn ss-btn-primary" type="submit"><svg class="ico"><use href="#i-play"/></svg> {{ __('websites.page.resume') }}</button></form>
            @else
                <form method="POST" action="{{ route('websites.pages.bulk', $domain) }}">@csrf<input type="hidden" name="action" value="approve"><input type="hidden" name="ids[]" value="{{ $page->id }}"><button class="ss-btn ss-btn-primary" type="submit"><svg class="ico"><use href="#i-check"/></svg> {{ __('websites.page.approve') }}</button></form>
            @endif
            <a href="{{ route('pages.edit', $page) }}" class="ss-btn ss-btn-secondary"><svg class="ico"><use href="#i-edit"/></svg> {{ __('websites.page.edit') }}</a>
        @endif
    </x-slot:actions>

    <div class="grid gap-4">
        @if ($page->approval_status === 'pending')
            <div class="ss-alert ss-alert-info" role="status"><svg class="ico"><use href="#i-search"/></svg><div>{{ __('websites.page.pending_notice', ['source' => $source]) }}</div></div>
        @elseif ($page->approval_status === 'rejected')
            <div class="ss-alert ss-alert-info" role="status"><svg class="ico"><use href="#i-x"/></svg><div>{{ __('websites.page.rejected_notice') }}</div></div>
        @elseif (! $page->is_active)
            <div class="ss-alert ss-alert-info" role="status"><svg class="ico"><use href="#i-pause"/></svg><div>{{ __('websites.page.paused_notice', ['date' => Time::dateTime($page->paused_at)]) }}</div></div>
        @endif

        <section class="grid grid-cols-2 gap-3 xl:grid-cols-4" aria-label="{{ __('websites.page.stats_label') }}">
            <article @class(['ss-stat', $statTone])>
                <span class="ss-stat-icon"><svg class="ico"><use href="#i-pulse"/></svg></span>
                <div class="min-w-0">
                    <div class="ss-stat-label">{{ __('websites.page.status') }}</div>
                    <div class="mt-1"><x-status :status="['pending' => 'queued', 'rejected' => 'closed'][$display] ?? $display" :label="$statusLabel" /></div>
                    <div class="ss-stat-caption">@if ($page->last_failure_type){{ $failure($page->last_failure_type) }} · @endif{{ __('websites.page.last_check', ['time' => Time::relative($page->last_checked_at)]) }}</div>
                </div>
            </article>
            <article class="ss-stat">
                <span class="ss-stat-icon"><svg class="ico"><use href="#i-check"/></svg></span>
                <div class="min-w-0"><div class="ss-stat-label">{{ __('websites.page.availability_30d') }}</div><div class="ss-stat-value">{{ $pct($stats30d['availability']) }}</div><div class="ss-stat-caption">{{ __('websites.page.availability_caption', ['checks' => $stats30d['checks'], 'target' => $pct($page->sla_target ?? $domain->sla_target ?? (float) config('serverscope.reports.default_target'), 2)]) }}</div></div>
            </article>
            <article class="ss-stat">
                <span class="ss-stat-icon"><svg class="ico"><use href="#i-clock"/></svg></span>
                <div class="min-w-0"><div class="ss-stat-label">{{ __('websites.page.response_24h') }}</div><div class="ss-stat-value">{{ $ms($stats24h['avg']) }}</div><div class="ss-stat-caption">@if ($stats24h['p95'] !== null){{ __('websites.page.p95', ['value' => $ms($stats24h['p95'])]) }}@endif{{ __('websites.page.last', ['value' => $ms($page->last_response_ms)]) }}</div></div>
            </article>
            <article @class(['ss-stat', 'is-bad' => $page->tls_valid === false])>
                <span class="ss-stat-icon"><svg class="ico"><use href="#i-lock"/></svg></span>
                <div class="min-w-0"><div class="ss-stat-label">{{ __('websites.page.tls') }}</div><div class="ss-stat-value">{{ ! $page->isHttps() ? __('websites.not_applicable') : ($page->tls_valid === null ? '—' : ($page->tls_valid ? __('websites.page.tls_valid') : __('websites.page.tls_error'))) }}</div><div class="ss-stat-caption">{!! __('websites.page.tls_caption', ['link' => '<a class="text-accent" href="'.e(route('domains.show', $domain)).'">'.e(__('websites.page.domain_link')).'</a>']) !!}</div></div>
            </article>
        </section>

        <div class="grid gap-4 lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
            <div class="grid min-w-0 content-start gap-4">
                <section class="ss-panel" aria-labelledby="response-title">
                    <div class="ss-panel-head"><div><h2 id="response-title" class="ss-panel-title">{{ __('websites.page.response_title') }}</h2><p class="ss-panel-sub">{{ __('websites.page.response_text') }}</p></div></div>
                    <x-line-chart :points="$chartPoints" unit=" ms" :label="__('websites.page.chart_label')" />
                    <h3 class="mt-5 mb-2 text-xs font-semibold text-muted">{{ __('websites.page.daily_title') }}</h3>
                    <div class="ss-strip" role="img" aria-label="{{ __('websites.page.daily_label') }}">
                        @foreach ($daily as $day)
                            <i @class(['up' => $day['uptime'] === 100.0, 'partial' => $day['uptime'] !== null && $day['uptime'] < 100 && $day['uptime'] >= 95, 'down' => $day['uptime'] !== null && $day['uptime'] < 95]) title="{{ $day['date'] }}: {{ $day['uptime'] === null ? __('websites.page.day_none') : Format::percent($day['uptime'], 2) }}"></i>
                        @endforeach
                    </div>
                </section>

                <section class="ss-panel" id="checks" aria-labelledby="checks-title">
                    <div class="ss-panel-head"><div><h2 id="checks-title" class="ss-panel-title">{{ __('websites.page.checks_title') }}</h2></div></div>
                    <nav class="ss-tabs-scroll mb-4" aria-label="{{ __('websites.page.checks_filter_label') }}">
                        <div class="ss-segmented">
                            @foreach ([null => 'all', 'down' => 'down', 'degraded' => 'degraded', 'up' => 'up'] as $key => $label)
                                <a href="{{ route('pages.show', [$page, 'result' => $key]) }}#checks" @if ($result === $key) aria-current="page" @endif>{{ __('websites.page.results.'.$label) }}</a>
                            @endforeach
                        </div>
                    </nav>
                    @if ($checks->isEmpty())
                        <x-empty-state icon="i-clock" :title="__('websites.page.checks_empty_title')">{{ $page->isMonitored() ? __('websites.page.checks_empty_monitored') : __('websites.page.checks_empty_off') }}</x-empty-state>
                    @else
                        <div class="ss-table-wrap">
                            <table class="ss-table ss-table-cards">
                                <thead><tr><th scope="col">{{ __('websites.page.col_time') }}</th><th scope="col">{{ __('websites.page.col_result') }}</th><th scope="col" class="num">{{ __('websites.page.col_http') }}</th><th scope="col" class="num">{{ __('websites.page.col_duration') }}</th><th scope="col">{{ __('websites.page.col_message') }}</th></tr></thead>
                                <tbody>
                                    @foreach ($checks as $check)
                                        <tr>
                                            <td class="is-primary whitespace-nowrap">{{ Time::dateTimeSeconds($check->checked_at) }}</td>
                                            <td data-label="{{ __('websites.page.col_result') }}"><span><x-status :status="$check->result" :label="$check->failure_type ? $failure($check->failure_type) : 'OK'" />@if ($check->content_changed)<span class="block text-xs text-muted">{{ __('websites.page.content_changed') }}</span>@endif</span></td>
                                            <td data-label="{{ __('websites.page.col_http') }}" class="num">{{ $check->http_status_code ?? '—' }}</td>
                                            <td data-label="{{ __('websites.page.col_duration') }}" class="num">{{ $ms($check->response_time_ms) }}</td>
                                            <td data-label="{{ __('websites.page.col_message') }}" class="text-xs [overflow-wrap:anywhere]"><span>{{ $check->message }}@if (! empty($check->details['redirects']))<span class="block text-muted">{{ __('websites.page.redirects') }} @foreach ($check->details['redirects'] as $hop){{ $hop['status'] }} {{ $hop['url'] }} → @endforeach{{ $check->details['final_url'] ?? '' }}</span>@endif</span></td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        <x-pagination :paginator="$checks" />
                    @endif
                </section>

                <section class="ss-panel" aria-labelledby="changes-title">
                    <div class="ss-panel-head"><div><h2 id="changes-title" class="ss-panel-title">{{ __('websites.page.changes_title') }}</h2><p class="ss-panel-sub">{{ __('websites.page.changes_text') }}</p></div></div>
                    @if ($changes->isEmpty())
                        <x-empty-state icon="i-history" :title="__('websites.page.changes_empty_title')">{{ $page->content_monitoring || $page->monitored_headers ? __('websites.page.changes_empty_watching') : __('websites.page.changes_empty_off') }}</x-empty-state>
                    @else
                        <ul class="ss-timeline">
                            @foreach ($changes as $change)
                                <li @class(['is-warn' => $change->significance === 'significant'])>
                                    <div class="ss-timeline-when">{{ Time::dateTimeSeconds($change->detected_at) }} · {{ $change->kind === 'headers' ? __('websites.page.change_headers') : ($change->significance === 'significant' ? __('websites.page.change_significant') : __('websites.page.change_minor')) }}</div>
                                    <div class="ss-timeline-what"><strong>{{ $change->summary }}</strong></div>
                                    @if (! empty($change->diff['added']) || ! empty($change->diff['removed']) || ! empty($change->diff['headers']))
                                        <div class="ss-diff">
                                            @foreach ($change->diff['added'] ?? [] as $line)<div class="ss-diff-add">+ {{ $line }}</div>@endforeach
                                            @foreach ($change->diff['removed'] ?? [] as $line)<div class="ss-diff-del">− {{ $line }}</div>@endforeach
                                            @foreach ($change->diff['headers'] ?? [] as $name => $values)<div><span class="text-fg">{{ $name }}</span>: {{ $values['from'] ?? __('websites.page.header_missing_before') }} → {{ $values['to'] ?? __('websites.page.header_missing_now') }}</div>@endforeach
                                        </div>
                                    @endif
                                    <div class="ss-timeline-body">
                                        @if ($change->acknowledged_at)
                                            {{ __('websites.page.acknowledged_by', ['name' => $change->acknowledger?->name ?? __('websites.page.unknown_user'), 'date' => Time::dateTime($change->acknowledged_at)]) }}
                                        @elseif ($isAdmin)
                                            <form method="POST" action="{{ route('page-changes.acknowledge', $change) }}">@csrf<button class="ss-btn ss-btn-secondary ss-btn-sm" type="submit"><svg class="ico"><use href="#i-check"/></svg> {{ __('websites.page.acknowledge') }}</button></form>
                                        @endif
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>
            </div>

            <div class="grid min-w-0 content-start gap-4">
                <section class="ss-panel" aria-labelledby="settings-title">
                    <div class="ss-panel-head"><div><h2 id="settings-title" class="ss-panel-title">{{ __('websites.page.settings_title') }}</h2></div></div>
                    <dl class="ss-dl">
                        <dt>{{ __('websites.page.interval_timeout') }}</dt><dd>{{ __('websites.page.interval_value', ['minutes' => $page->check_interval_minutes, 'seconds' => $page->timeout_seconds]) }}</dd>
                        <dt>{{ __('websites.page.thresholds') }}</dt><dd>{{ __('websites.page.thresholds_value', ['failure' => $page->failure_threshold, 'recovery' => $page->recovery_threshold]) }}</dd>
                        <dt>{{ __('websites.page.status_codes') }}</dt><dd>{{ $page->expected_status_codes ?: '200-399' }}</dd>
                        <dt>{{ __('websites.page.max_response') }}</dt><dd>{{ $page->max_response_ms ? __('websites.page.max_response_value', ['ms' => $page->max_response_ms]) : '—' }}</dd>
                        <dt>{{ __('websites.page.redirects_label') }}</dt><dd>{{ $page->follow_redirects ? __('websites.page.follow') : __('websites.page.no_follow') }}@if ($page->expected_final_url)<br>{{ __('websites.page.expected_final') }} <span class="[overflow-wrap:anywhere]">{{ $page->expected_final_url }}</span>@endif @if ($page->last_final_url && $page->last_final_url !== $page->url)<br>{{ __('websites.page.last_final') }} <span class="[overflow-wrap:anywhere]">{{ $page->last_final_url }}</span>@endif</dd>
                        <dt>{{ __('websites.page.content') }}</dt><dd>@if ($page->keyword_present){{ __('websites.page.must_contain', ['text' => $page->keyword_present]) }}<br>@endif @if ($page->keyword_absent){{ __('websites.page.must_not_contain', ['text' => $page->keyword_absent]) }}@endif @if (! $page->keyword_present && ! $page->keyword_absent)—@endif</dd>
                        <dt>{{ __('websites.page.header_checks') }}</dt><dd class="whitespace-pre-line font-mono text-xs">{{ PageRequest::headerAssertionsText($page->header_assertions) ?: '—' }}</dd>
                        <dt>{{ __('websites.page.watched_headers') }}</dt><dd class="font-mono text-xs">{{ implode(', ', $page->monitored_headers ?? []) ?: '—' }}</dd>
                        <dt>{{ __('websites.page.content_monitoring') }}</dt><dd>{{ $page->content_monitoring ? ($page->content_selector ? __('websites.page.content_on_selector', ['selector' => $page->content_selector]) : __('websites.page.content_on_page')) : __('websites.off') }}@if ($page->content_exclusions)<br>{{ __('websites.page.excluded', ['list' => implode(', ', $page->content_exclusions)]) }}@endif</dd>
                        <dt>{{ __('websites.page.alerts') }}</dt><dd>{{ $page->alerts_enabled ? Label::of('severity', $page->alert_severity, \App\Support\Severity::LABELS) : __('websites.off') }}{{ $page->notify_content_changes ? __('websites.page.alerts_content') : '' }}</dd>
                        <dt>{{ __('websites.page.source') }}</dt><dd>{{ $source }}@if ($page->discovered_at){{ __('websites.page.discovered', ['date' => Time::dateTime($page->discovered_at)]) }}@endif</dd>
                        <dt>{{ __('websites.page.next_check') }}</dt><dd>{{ $page->isMonitored() ? Time::dateTime($page->next_check_at) : '—' }}</dd>
                    </dl>
                </section>

                <section class="ss-panel" aria-labelledby="page-incidents-title">
                    <div class="ss-panel-head"><div><h2 id="page-incidents-title" class="ss-panel-title">{{ __('websites.page.incidents_title') }}</h2></div></div>
                    @if ($allIncidents->isEmpty())
                        <x-empty-state icon="i-check" :title="__('websites.page.incidents_empty')" />
                    @else
                        <ul class="ss-list">
                            @foreach ($allIncidents as $incident)
                                <li>
                                    <div class="min-w-0">
                                        <a class="text-accent" href="{{ route('incidents.show', $incident) }}">{{ $incident->title }}</a>
                                        <div class="ss-list-sub mt-1 flex flex-wrap items-center gap-1.5">{{ Time::dateTime($incident->opened_at) }} · <x-status :status="$incident->status" />@if ($incident->subject_type === 'domain'){{ __('websites.page.website_wide') }}@endif</div>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>

                @if ($isAdmin)
                    <section class="ss-panel ss-panel-danger" aria-labelledby="delete-title">
                        <h2 id="delete-title" class="ss-panel-title">{{ __('websites.page.delete_title') }}</h2>
                        <p class="ss-panel-sub mb-4">{{ __('websites.page.delete_text') }}</p>
                        <form method="POST" action="{{ route('pages.destroy', $page) }}" data-confirm="{{ __('websites.page.delete_confirm') }}">
                            @csrf @method('DELETE')
                            <button class="ss-btn ss-btn-danger" type="submit"><svg class="ico"><use href="#i-trash"/></svg> {{ __('websites.page.delete') }}</button>
                        </form>
                    </section>
                @endif
            </div>
        </div>
    </div>
</x-layouts.app>
