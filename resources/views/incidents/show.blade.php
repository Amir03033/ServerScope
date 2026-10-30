@php
    use App\Models\AlertRule;
    use App\Models\Incident;
    use App\Models\IncidentEvent;
    use App\Models\MonitoredPage;
    use App\Services\Dependencies\ResourceCatalog;
    use App\Services\Incidents\IncidentMetrics;
    use App\Support\Format;
    use App\Support\Label;
    use App\Support\Severity;
    use App\Support\StatusLabel;
    use App\Support\Time;

    $subject = $incident->subject;
    $isAdmin = auth()->user()->can('admin');
    $eventTone = fn (string $type) => match ($type) {
        'opened', 'reopened', 'escalated', 'severity_changed' => 'is-bad',
        'recovered', 'resolved', 'resource_recovered' => 'is-ok',
        'acknowledged', 'investigating' => 'is-warn',
        'correlated', 'child_added', 'detached', 'resource_affected', 'comment' => 'is-info',
        default => null,
    };
    $typeLabel = fn (?string $type) => Label::of('resource_type', $type, ResourceCatalog::TYPES);
    $impactFailure = fn (?string $type) => Lang::has('incidents.show.impact_failure.'.$type)
        ? __('incidents.show.impact_failure.'.$type)
        : (array_key_exists($type, MonitoredPage::FAILURE_LABELS) ? Label::of('page_failure', $type, MonitoredPage::FAILURE_LABELS) : str_replace('_', ' ', $type));

    // Status bar: the lifecycle in order, the current step highlighted.
    $steps = Incident::STATUSES;
    $current = array_search($incident->status, $steps, true);
    $actions = array_filter(['acknowledged', 'investigating'], fn ($target) => $incident->canTransitionTo($target));
@endphp

<x-layouts.app :title="__('incidents.show.title', ['id' => $incident->id])">
    <x-slot:breadcrumb><span>›</span> <a href="{{ route('incidents.index') }}">{{ __('incidents.title') }}</a> <span>›</span> #{{ $incident->id }}</x-slot:breadcrumb>
    <x-slot:pageTitle>{{ $incident->title }}</x-slot:pageTitle>
    <x-slot:subtitle>{{ __('incidents.show.subtitle', ['source' => Label::of('incident_source', $incident->source, Incident::SOURCE_LABELS), 'date' => Time::dateTimeSeconds($incident->opened_at)]) }}</x-slot:subtitle>
    <x-slot:actions>
        <a href="{{ route('incidents.index') }}" class="ss-btn ss-btn-ghost"><svg class="ico"><use href="#i-arrow-left"/></svg> {{ __('incidents.overview') }}</a>
    </x-slot:actions>

    {{-- Status bar with the actions that are possible now (directly under it on mobile). --}}
    <section class="ss-panel mb-4 flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
        <div class="flex min-w-0 flex-wrap items-center gap-3">
            <x-severity :severity="$incident->severity" />
            <ol class="ss-progress min-w-0 flex-1" aria-label="{{ __('incidents.show.progress_label') }}">
                @foreach ($steps as $index => $step)
                    <li @class(['is-done' => $current !== false && $index < $current]) @if ($index === $current) aria-current="step" @endif>{{ StatusLabel::for($step) }}</li>
                @endforeach
            </ol>
        </div>
        <div class="flex flex-wrap gap-2">
            @foreach ($actions as $target)
                <form method="POST" action="{{ route('incidents.transition', $incident) }}">
                    @csrf
                    <input type="hidden" name="status" value="{{ $target }}">
                    <button type="submit" @class(['ss-btn', 'ss-btn-primary' => $loop->first, 'ss-btn-secondary' => ! $loop->first])>{{ __('incidents.show.actions.'.$target) }}</button>
                </form>
            @endforeach
            @if ($incident->canTransitionTo('resolved'))
                <a href="#resolve" @class(['ss-btn', 'ss-btn-primary' => $actions === [], 'ss-btn-secondary' => $actions !== []])><svg class="ico"><use href="#i-check"/></svg> {{ __('incidents.show.resolve_title') }}</a>
            @endif
            @if ($isAdmin && $incident->canTransitionTo('open'))
                <form method="POST" action="{{ route('incidents.transition', $incident) }}">
                    @csrf <input type="hidden" name="status" value="open">
                    <button type="submit" class="ss-btn ss-btn-secondary">{{ __('incidents.show.actions.reopen') }}</button>
                </form>
            @endif
        </div>
    </section>

    <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_22rem] lg:items-start">
        <div class="grid min-w-0 gap-4">
            <section class="ss-panel" aria-labelledby="summary-title">
                <div class="ss-panel-head">
                    <div><h2 id="summary-title" class="ss-panel-title">{{ __('incidents.show.summary') }}</h2></div>
                    <x-status :status="$incident->status" />
                </div>
                @if ($incident->description)
                    <p class="mt-0 mb-3 whitespace-pre-line">{{ $incident->description }}</p>
                @endif
                <dl class="ss-dl">
                    <dt>{{ __('incidents.show.subject') }}</dt>
                    <dd>
                        @if ($subject)
                            @php $subjectUrl = ResourceCatalog::url($subject); @endphp
                            @if ($subjectUrl)<a class="text-accent" href="{{ $subjectUrl }}">{{ ResourceCatalog::name($subject) }}</a>@else{{ ResourceCatalog::name($subject) }}@endif
                            <span class="text-muted">({{ mb_strtolower($typeLabel($incident->subject_type)) }})</span>
                        @else
                            —
                        @endif
                    </dd>
                    @if ($incident->started_at && $incident->started_at->lt($incident->opened_at))
                        <dt>{{ __('incidents.show.started') }}</dt><dd>{{ Time::dateTimeSeconds($incident->started_at) }} <span class="text-muted">{{ __('incidents.show.started_hint') }}</span></dd>
                    @endif
                    <dt>{{ __('incidents.show.detected') }}</dt><dd>{{ Time::dateTimeSeconds($incident->opened_at) }}@if ($incident->timeToDetect() !== null) <span class="text-muted">{{ __('incidents.show.detect_time', ['duration' => IncidentMetrics::humanDuration($incident->timeToDetect())]) }}</span>@endif</dd>
                    <dt>{{ __('incidents.show.last_seen') }}</dt><dd>{{ __('incidents.show.last_seen_value', ['date' => Time::dateTimeSeconds($incident->last_occurrence_at), 'count' => $incident->occurrence_count]) }}</dd>
                    <dt>{{ __('incidents.show.acknowledged') }}</dt><dd>{{ $incident->acknowledged_at ? __('incidents.show.acknowledged_value', ['date' => Time::dateTimeSeconds($incident->acknowledged_at), 'name' => $incident->acknowledger?->name ?? __('incidents.unknown'), 'duration' => IncidentMetrics::humanDuration($incident->timeToAcknowledge())]) : '—' }}</dd>
                    <dt>{{ __('incidents.show.resolved') }}</dt><dd>{{ $incident->resolved_at ? __('incidents.show.resolved_value', ['date' => Time::dateTimeSeconds($incident->resolved_at), 'name' => $incident->resolver?->name ?? __('incidents.show.auto_recovered'), 'duration' => IncidentMetrics::humanDuration($incident->timeToResolve())]) : '—' }}</dd>
                    @if ($incident->closed_at)<dt>{{ __('incidents.show.closed') }}</dt><dd>{{ Time::dateTimeSeconds($incident->closed_at) }}</dd>@endif
                    @if ($incident->reopened_count)<dt>{{ __('incidents.show.reopened') }}</dt><dd>{{ $incident->reopened_count }}×</dd>@endif
                    <dt>{{ __('incidents.show.recurrence') }}</dt><dd>{{ __('incidents.show.recurrence_value', ['last30' => $recurrence['last_30d'], 'total' => $recurrence['total']]) }}</dd>
                    @if ($incident->resolution_notes)<dt>{{ __('incidents.show.resolution') }}</dt><dd class="whitespace-pre-line">{{ $incident->resolution_notes }}</dd>@endif
                    <dt>{{ __('incidents.show.fingerprint') }}</dt><dd class="font-mono text-xs">{{ $incident->fingerprint }}</dd>
                </dl>
            </section>

            @if ($incident->parent)
                <section class="ss-panel" aria-labelledby="grouped-title">
                    <div class="ss-panel-head">
                        <div>
                            <h2 id="grouped-title" class="ss-panel-title">{{ __('incidents.show.grouped_title') }}</h2>
                            <p class="ss-panel-sub">{!! __('incidents.show.grouped_text', [
                                'link' => '<a class="text-accent" href="'.e(route('incidents.show', $incident->parent)).'">'.e(__('incidents.show.grouped_link', ['id' => $incident->parent->id, 'title' => $incident->parent->title])).'</a>',
                                'status' => e(StatusLabel::for($incident->parent->status)),
                            ]) !!}</p>
                        </div>
                        @if ($isAdmin)
                            <form method="POST" action="{{ route('incidents.detach', $incident) }}" data-confirm="{{ __('incidents.show.detach_confirm') }}">
                                @csrf
                                <button class="ss-btn ss-btn-secondary ss-btn-sm" type="submit">{{ __('incidents.show.detach') }}</button>
                            </form>
                        @endif
                    </div>
                    @if ($incident->correlation['reason'] ?? null)
                        <p class="m-0 text-sm"><strong>{{ __('incidents.show.reason') }}</strong> {{ $incident->correlation['reason'] }}</p>
                    @endif
                    <p class="mt-2 mb-0 text-xs text-muted">{{ __('incidents.show.grouped_note') }}</p>
                </section>
            @endif

            @if ($incident->children->isNotEmpty())
                <section class="ss-panel" aria-labelledby="children-title">
                    <div class="ss-panel-head"><div><h2 id="children-title" class="ss-panel-title">{{ __('incidents.show.children_title', ['count' => $incident->children->count()]) }}</h2><p class="ss-panel-sub">{{ __('incidents.show.children_text') }}</p></div></div>
                    <div class="ss-table-wrap">
                        <table class="ss-table ss-table-cards">
                            <thead><tr><th scope="col">{{ __('incidents.show.col_incident') }}</th><th scope="col">{{ __('incidents.show.col_subject') }}</th><th scope="col">{{ __('incidents.show.col_status') }}</th><th scope="col">{{ __('incidents.show.col_opened') }}</th></tr></thead>
                            <tbody>
                                @foreach ($incident->children as $child)
                                    <tr>
                                        <td class="is-primary"><a class="font-semibold text-fg no-underline hover:text-accent" href="{{ route('incidents.show', $child) }}">#{{ $child->id }} {{ $child->title }}</a><span class="block text-xs text-muted">{{ $child->correlation['reason'] ?? '' }}</span></td>
                                        <td data-label="{{ __('incidents.show.col_subject') }}">{{ $child->subjectName() }}</td>
                                        <td data-label="{{ __('incidents.show.col_status') }}"><x-status :status="$child->status" /></td>
                                        <td data-label="{{ __('incidents.show.col_opened') }}" class="whitespace-nowrap">{{ Time::dateTime($child->opened_at) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>
            @endif

            @if ($incident->impacts->isNotEmpty())
                @php $failingImpacts = $incident->impacts->where('status', 'failing')->count(); @endphp
                <section class="ss-panel" aria-labelledby="impacts-title">
                    <div class="ss-panel-head"><div><h2 id="impacts-title" class="ss-panel-title">{{ __('incidents.show.impacts_title') }}</h2><p class="ss-panel-sub">{{ __('incidents.show.impacts_text', ['failing' => $failingImpacts, 'total' => $incident->impacts->count()]) }}</p></div></div>
                    <div class="ss-table-wrap">
                        <table class="ss-table ss-table-cards">
                            <thead><tr><th scope="col">{{ __('incidents.show.col_resource') }}</th><th scope="col">{{ __('incidents.show.col_role') }}</th><th scope="col">{{ __('incidents.show.col_failure') }}</th><th scope="col">{{ __('incidents.show.col_status') }}</th><th scope="col">{{ __('incidents.show.col_first_failed') }}</th><th scope="col">{{ __('incidents.show.col_recovered') }}</th></tr></thead>
                            <tbody>
                                @foreach ($incident->impacts->sortByDesc(fn ($i) => $i->status === 'failing') as $impact)
                                    @php $impactUrl = ResourceCatalog::url($impact->resource); @endphp
                                    <tr>
                                        <td class="is-primary">
                                            @if ($impactUrl)<a class="font-semibold text-fg no-underline hover:text-accent" href="{{ $impactUrl }}">{{ ResourceCatalog::name($impact->resource) }}</a>@else{{ ResourceCatalog::name($impact->resource) }}@endif
                                            <span class="block text-xs text-muted">{{ $typeLabel($impact->resource_type) }}@if ($impact->message) · {{ $impact->message }}@endif</span>
                                        </td>
                                        <td data-label="{{ __('incidents.show.col_role') }}">{{ $impact->role === 'cause' ? __('incidents.show.role_cause') : __('incidents.show.role_affected') }}</td>
                                        <td data-label="{{ __('incidents.show.col_failure') }}">{{ $impact->failure_type ? $impactFailure($impact->failure_type) : '—' }}</td>
                                        <td data-label="{{ __('incidents.show.col_status') }}"><x-status :status="$impact->status === 'failing' ? 'down' : 'up'" :label="$impact->status === 'failing' ? __('incidents.show.impact_failing') : __('incidents.show.impact_recovered')" /></td>
                                        <td data-label="{{ __('incidents.show.col_first_failed') }}" class="whitespace-nowrap">{{ Time::dateTime($impact->first_failed_at) }}</td>
                                        <td data-label="{{ __('incidents.show.col_recovered') }}" class="whitespace-nowrap">{{ Time::dateTime($impact->recovered_at) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>
            @endif

            <section class="ss-panel" id="timeline" aria-labelledby="timeline-title">
                <div class="ss-panel-head"><div><h2 id="timeline-title" class="ss-panel-title">{{ __('incidents.show.timeline_title') }}</h2><p class="ss-panel-sub">{{ __('incidents.show.timeline_text') }}</p></div></div>
                <ul class="ss-timeline">
                    @foreach ($incident->events as $event)
                        <li @class([$eventTone($event->type)])>
                            <div class="ss-timeline-when"><time datetime="{{ $event->created_at?->toIso8601String() }}">{{ Time::dateTimeSeconds($event->created_at) }}</time> · {{ $event->user?->name ?? __('incidents.system') }}</div>
                            <div class="ss-timeline-what"><strong>{{ Label::of('incident_event', $event->type, IncidentEvent::TYPE_LABELS) }}</strong></div>
                            @if ($event->message)<div class="ss-timeline-body whitespace-pre-line text-fg">{{ $event->message }}</div>@endif
                        </li>
                    @endforeach
                </ul>

                <form method="POST" action="{{ route('incidents.comment', $incident) }}" class="mt-6 border-t border-line pt-5">
                    @csrf
                    <label class="ss-label" for="message">{{ __('incidents.show.note_label') }}</label>
                    <textarea id="message" class="ss-input mt-1.5 min-h-24" name="message" maxlength="5000" required placeholder="{{ __('incidents.show.note_placeholder') }}">{{ old('message') }}</textarea>
                    <div class="mt-3"><button class="ss-btn ss-btn-secondary" type="submit"><svg class="ico"><use href="#i-message"/></svg> {{ __('incidents.show.note_submit') }}</button></div>
                </form>
            </section>

            @if ($checks->isNotEmpty())
                <section class="ss-panel" aria-labelledby="checks-title">
                    <div class="ss-panel-head"><div><h2 id="checks-title" class="ss-panel-title">{{ __('incidents.show.checks_title') }}</h2><p class="ss-panel-sub">{{ __('incidents.show.checks_text') }}</p></div></div>
                    <div class="ss-table-wrap">
                        <table class="ss-table ss-table-cards">
                            <thead><tr><th scope="col">{{ __('incidents.show.col_time') }}</th><th scope="col">{{ __('incidents.show.col_type') }}</th><th scope="col">{{ __('incidents.show.col_result') }}</th><th scope="col">{{ __('incidents.show.col_message') }}</th></tr></thead>
                            <tbody>
                                @foreach ($checks as $check)
                                    <tr>
                                        <td class="is-primary whitespace-nowrap">{{ Time::dateTimeSeconds($check->checked_at) }}</td>
                                        <td data-label="{{ __('incidents.show.col_type') }}"><span class="ss-chip">{{ strtoupper($check->type) }}</span></td>
                                        <td data-label="{{ __('incidents.show.col_result') }}">{{ str_replace('_', ' ', $check->result) }}@if ($check->http_status_code) ({{ $check->http_status_code }})@endif</td>
                                        <td data-label="{{ __('incidents.show.col_message') }}" class="text-xs [overflow-wrap:anywhere]">{{ $check->message }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>
            @endif

            @if ($incident->alerts->isNotEmpty())
                <section class="ss-panel" aria-labelledby="alerts-title">
                    <div class="ss-panel-head"><div><h2 id="alerts-title" class="ss-panel-title">{{ __('incidents.show.alerts_title') }}</h2></div></div>
                    <div class="ss-table-wrap">
                        <table class="ss-table ss-table-cards">
                            <thead><tr><th scope="col">{{ __('incidents.show.col_server') }}</th><th scope="col">{{ __('incidents.show.col_metric') }}</th><th scope="col" class="num">{{ __('incidents.show.col_value') }}</th><th scope="col">{{ __('incidents.show.col_status') }}</th><th scope="col">{{ __('incidents.show.col_triggered') }}</th></tr></thead>
                            <tbody>
                                @foreach ($incident->alerts as $alert)
                                    <tr>
                                        <td class="is-primary">{{ $alert->server?->name ?? '—' }}</td>
                                        <td data-label="{{ __('incidents.show.col_metric') }}">{{ Label::of('alert_metric', $alert->metric, AlertRule::METRIC_LABELS) }}@if ($alert->dimension) · {{ $alert->dimension }}@endif</td>
                                        <td data-label="{{ __('incidents.show.col_value') }}" class="num">{{ Format::number($alert->observed_value, 1) }}{{ AlertRule::metricUnit($alert->metric) }}</td>
                                        <td data-label="{{ __('incidents.show.col_status') }}">{{ $alert->status }}</td>
                                        <td data-label="{{ __('incidents.show.col_triggered') }}" class="whitespace-nowrap">{{ Time::dateTime($alert->triggered_at) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>
            @endif
        </div>

        {{-- Fixed column: assignment, resolving, closing, severity and history. --}}
        <aside class="grid min-w-0 gap-4">
            <section class="ss-panel" aria-labelledby="assign-title">
                <h2 id="assign-title" class="ss-panel-title mb-3">{{ __('incidents.show.assign_title') }}</h2>
                <form method="POST" action="{{ route('incidents.assign', $incident) }}" class="flex flex-wrap gap-2">
                    @csrf
                    <label class="sr-only" for="assigned_to">{{ __('incidents.show.assign_to') }}</label>
                    <select id="assigned_to" name="assigned_to" class="ss-input min-w-40 flex-1">
                        <option value="">{{ __('incidents.nobody') }}</option>
                        @foreach ($users as $user)
                            <option value="{{ $user->id }}" @selected($incident->assigned_to === $user->id)>{{ $user->name }}{{ $user->id === auth()->id() ? __('incidents.show.me') : '' }}</option>
                        @endforeach
                    </select>
                    <button class="ss-btn ss-btn-secondary" type="submit">{{ __('incidents.show.save') }}</button>
                </form>
            </section>

            @if ($incident->canTransitionTo('resolved'))
                <section class="ss-panel" id="resolve" aria-labelledby="resolve-title">
                    <h2 id="resolve-title" class="ss-panel-title">{{ __('incidents.show.resolve_title') }}</h2>
                    <p class="ss-panel-sub mb-3">{{ __('incidents.show.resolve_text', ['minutes' => config('serverscope.incidents.reopen_window_minutes')]) }}</p>
                    <form method="POST" action="{{ route('incidents.transition', $incident) }}">
                        @csrf
                        <input type="hidden" name="status" value="resolved">
                        <label class="ss-label" for="notes">{{ __('incidents.show.resolve_notes') }}</label>
                        <textarea id="notes" class="ss-input mt-1.5 min-h-24" name="notes" minlength="3" maxlength="5000" required placeholder="{{ __('incidents.show.resolve_placeholder') }}" @error('notes') aria-invalid="true" aria-describedby="notes-error" @enderror>{{ old('notes') }}</textarea>
                        @error('notes')<p id="notes-error" class="ss-error">{{ $message }}</p>@enderror
                        <div class="mt-3"><button class="ss-btn ss-btn-primary" type="submit">{{ __('incidents.show.resolve_submit') }}</button></div>
                    </form>
                </section>
            @endif

            @if ($isAdmin && $incident->canTransitionTo('closed'))
                <section class="ss-panel" aria-labelledby="close-title">
                    <h2 id="close-title" class="ss-panel-title">{{ __('incidents.show.close_title') }}</h2>
                    <p class="ss-panel-sub mb-3">{{ __('incidents.show.close_text') }}</p>
                    <form method="POST" action="{{ route('incidents.transition', $incident) }}">
                        @csrf
                        <input type="hidden" name="status" value="closed">
                        <label class="ss-label" for="close-notes">{{ __('incidents.show.close_notes') }}</label>
                        <textarea id="close-notes" class="ss-input mt-1.5 min-h-20" name="notes" maxlength="5000"></textarea>
                        <div class="mt-3"><button class="ss-btn ss-btn-secondary" type="submit">{{ __('incidents.show.close_submit') }}</button></div>
                    </form>
                </section>
            @endif

            @if ($isAdmin)
                <section class="ss-panel" aria-labelledby="severity-title">
                    <h2 id="severity-title" class="ss-panel-title mb-3">{{ __('incidents.show.severity_title') }}</h2>
                    <form method="POST" action="{{ route('incidents.severity', $incident) }}" class="flex gap-2">
                        @csrf
                        <select name="severity" class="ss-input flex-1" aria-label="{{ __('incidents.show.severity_title') }}">
                            @foreach (Severity::options() as $v => $l)<option value="{{ $v }}" @selected($incident->severity === $v)>{{ Label::of('severity', $v, Severity::LABELS) }}</option>@endforeach
                        </select>
                        <button class="ss-btn ss-btn-secondary" type="submit">{{ __('incidents.show.severity_change') }}</button>
                    </form>
                </section>
            @endif

            @if ($related->isNotEmpty())
                <section class="ss-panel" aria-labelledby="previous-title">
                    <h2 id="previous-title" class="ss-panel-title">{{ __('incidents.show.previous_title') }}</h2>
                    <p class="ss-panel-sub mb-2">{{ __('incidents.show.previous_text', ['subject' => $incident->subjectName()]) }}</p>
                    <ul class="ss-list">
                        @foreach ($related as $other)
                            <li>
                                <div class="min-w-0">
                                    <a class="text-accent" href="{{ route('incidents.show', $other) }}">{{ $other->title }}</a>
                                    <div class="ss-list-sub">{{ Time::dateTime($other->opened_at) }} · {{ $other->status }}</div>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif
        </aside>
    </div>
</x-layouts.app>
