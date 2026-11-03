{{-- NEXUS Intelligence: a stored, deterministic investigation of an incident. All texts from the snapshot are printed escaped. --}}
@php
    use App\Services\Intelligence\Presenter;
    use App\Services\Incidents\Evidence\ScopeResolver;
    use App\Support\Time;

    $inv = $investigation;
    $p = $inv?->hasResult() ? new Presenter($inv) : null;
    $r = $p?->result() ?? [];
    $primary = $p?->primary();
    $isAdmin = auth()->user()->can('admin');
    $confidenceTone = fn (string $level) => match ($level) {
        'high', 'medium' => 'ss-status-info',
        default => 'ss-status-muted',
    };
    $statusTone = fn (string $status) => match ($status) {
        'completed' => 'ss-status-ok',
        'partial' => 'ss-status-warn',
        'failed' => 'ss-status-bad',
        'queued', 'running' => 'ss-status-info',
        default => 'ss-status-muted',
    };
    $eventTone = fn (array $e) => match (true) {
        $e['category'] === 'recovery' => 'is-ok',
        $e['problem'] => 'is-bad',
        in_array($e['category'], ['change', 'anomaly', 'maintenance'], true) || $e['kind'] === 'coverage' => 'is-warn',
        default => 'is-info',
    };
    $sourceUrl = fn (?array $source) => match ($source['type'] ?? null) {
        'incident' => route('incidents.show', $source['id']),
        'deployment' => Route::has('deployments.show') ? route('deployments.show', $source['id']) : null,
        default => null,
    };
    $evidenceList = function (array $keys) use ($p) {
        return array_values(array_filter(array_map(fn ($k) => $p->item($k), $keys)));
    };
    $limitations = $r ? count($r['gaps']) + count($r['truncated']) + ($r['impact']['truncated'] ? 1 : 0) : 0;
    $trigger = $inv ? ($inv->trigger === 'manual' ? ($inv->requester ? __('investigations.trigger.manual', ['name' => $inv->requester->name]) : __('investigations.trigger.manual_unknown')) : __('investigations.trigger.'.$inv->trigger)) : null;
    $newest = $history->first();
@endphp

<x-layouts.app :title="__('investigations.title', ['id' => $incident->id])">
    <x-slot:breadcrumb><span>›</span> <a href="{{ route('incidents.index') }}">{{ __('incidents.title') }}</a> <span>›</span> <a href="{{ route('incidents.show', $incident) }}">#{{ $incident->id }}</a> <span>›</span> {{ __('investigations.open') }}</x-slot:breadcrumb>
    <x-slot:pageTitle>{{ $incident->title }}</x-slot:pageTitle>
    <x-slot:subtitle>{{ __('investigations.name') }} · {{ __('investigations.subtitle') }}</x-slot:subtitle>
    <x-slot:actions>
        <a href="{{ route('incidents.show', $incident) }}" class="ss-btn ss-btn-ghost"><svg class="ico"><use href="#i-arrow-left"/></svg> {{ __('investigations.back') }}</a>
        <a href="{{ route('incidents.time-machine', $incident) }}" class="ss-btn ss-btn-ghost">{{ __('investigations.time_machine') }}</a>
        @if ($p)
            <a href="{{ route('investigations.export', $inv) }}" class="ss-btn ss-btn-ghost" data-testid="investigation-export">{{ __('investigations.export') }}</a>
        @endif
        @if ($isAdmin && ! $inv?->isPending())
            <form method="POST" action="{{ route('investigations.store', $incident) }}">@csrf<button class="ss-btn ss-btn-secondary" type="submit" data-testid="investigation-rerun">{{ $inv ? __('investigations.rerun') : __('investigations.run') }}</button></form>
        @endif
    </x-slot:actions>

    <div class="grid gap-4" data-testid="investigation" data-status="{{ $inv?->status ?? 'none' }}">
        <div class="ss-alert ss-alert-info" role="note"><svg class="ico"><use href="#i-alert"/></svg><div>{{ __('investigations.caveat') }}</div></div>

        @if ($inv === null)
            <section class="ss-panel" data-testid="investigation-empty">
                <p class="m-0 text-sm text-fg">{{ __('investigations.states.none') }}</p>
                @if ($isAdmin)<p class="m-0 mt-1 text-sm text-muted">{{ __('investigations.states.none_admin') }}</p>@endif
            </section>
        @elseif ($inv->isPending())
            <section class="ss-panel" role="status" data-testid="investigation-pending">
                <p class="m-0 text-sm">{{ __('investigations.states.'.$inv->status) }}</p>
                <x-auto-refresh :seconds="10" />
            </section>
        @elseif ($inv->status === 'failed')
            <div class="ss-alert ss-alert-error" role="alert" data-testid="investigation-failed"><svg class="ico"><use href="#i-alert"/></svg><div>{{ __('investigations.states.failed') }} {{ __('investigations.errors.'.(in_array($inv->error_code, ['integrity_violation', 'internal_error', 'queue_failure', 'incident_missing'], true) ? $inv->error_code : 'internal_error')) }}</div></div>
        @endif

        @if ($inv?->status === 'superseded' && $newest && $newest->id !== $inv->id)
            <div class="ss-alert ss-alert-info" role="note"><svg class="ico"><use href="#i-alert"/></svg><div>{{ __('investigations.states.superseded') }} <a class="underline" href="{{ route('investigations.show', $newest) }}">{{ __('investigations.states.newer') }}</a></div></div>
        @endif
        @if ($inv?->status === 'partial')
            <div class="ss-alert ss-alert-warning" role="alert" data-testid="investigation-partial"><svg class="ico"><use href="#i-alert"/></svg><div>{{ __('investigations.states.partial') }}</div></div>
        @endif
        @if ($p && ($r['truncated'] !== [] || $r['impact']['truncated']))
            <div class="ss-alert ss-alert-warning" role="note" data-testid="investigation-truncated"><svg class="ico"><use href="#i-alert"/></svg><div>{{ __('investigations.states.truncated') }}</div></div>
        @endif

        @if ($p)
            <p class="m-0 text-sm text-muted">{{ __('investigations.historical', ['time' => Time::dateTimeSeconds($inv->as_of)]) }} {{ __('investigations.ai_note') }}</p>

            <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_22rem] lg:items-start">
                <div class="grid min-w-0 gap-4">
                    {{-- B. Leading explanation --}}
                    @php $leading = $primary['code'] !== 'insufficient_evidence'; @endphp
                    <section @class(['ss-panel ss-diagnosis', 'is-info' => $leading]) aria-labelledby="primary-title" data-testid="investigation-primary" data-code="{{ $primary['code'] }}" data-confidence="{{ $primary['confidence'] }}">
                        <div class="ss-panel-head !mb-2">
                            <div>
                                <p class="m-0 text-xs text-muted">{{ __('investigations.primary.title') }}</p>
                                <h2 id="primary-title" class="ss-panel-title">{{ $p->hypothesis($primary) }}</h2>
                                <p class="ss-panel-sub">{{ $leading ? $p->explanation($primary) : __('investigations.primary.none') }}</p>
                            </div>
                            <span class="ss-status {{ $confidenceTone($primary['confidence']) }}">{{ __('investigations.confidence.label') }}: {{ __('investigations.confidence.'.$primary['confidence']) }}</span>
                        </div>

                        <p class="m-0 text-xs text-muted"><strong>{{ __('investigations.primary.why') }}:</strong> {{ implode(' · ', array_map(fn ($c) => __('investigations.reasons.'.$c), $primary['reasons'])) }}</p>

                        @if ($primary['path'] !== [] && count($primary['path']) > 1)
                            <p class="m-0 mt-2 text-sm"><strong>{{ __('investigations.primary.path') }}:</strong> {{ $p->path($primary['path']) }}</p>
                        @endif

                        @foreach (['supporting' => $primary['supporting'], 'contradicting' => $primary['contradicting']] as $role => $keys)
                            <h3 class="m-0 mt-3 text-sm font-semibold">{{ __('investigations.primary.'.$role) }}</h3>
                            @forelse ($evidenceList($keys) as $item)
                                <p class="m-0 mt-1 text-sm">• <a class="ss-chip no-underline" href="#{{ $item['key'] }}">{{ $item['key'] }}</a> {{ $p->evidence($item) }}</p>
                            @empty
                                <p class="m-0 mt-1 text-sm text-muted">{{ __('investigations.primary.none_listed') }}</p>
                            @endforelse
                        @endforeach

                        @if ($primary['missing'] !== [])
                            <h3 class="m-0 mt-3 text-sm font-semibold">{{ __('investigations.primary.missing') }}</h3>
                            @foreach ($primary['missing'] as $missing)
                                <p class="m-0 mt-1 text-sm text-muted">• {{ __('investigations.missing.'.$missing) }}</p>
                            @endforeach
                        @endif
                    </section>

                    {{-- AI interpretation of this incident's investigation (separate layer; never changes the result above). --}}
                    <section class="ss-panel" aria-label="{{ __('investigations.rca.ai') }}">
                        @include('investigations._ai', ['analysis' => $analysis, 'deterministic' => $primary['code'] !== 'insufficient_evidence' ? $primary : null, 'testid' => 'investigation-ai'])
                        @if ($analysis?->interpretsInvestigation() && $analysis->investigation_id && $analysis->investigation_id !== $inv->id)
                            <p class="m-0 mt-2 text-xs text-muted">{{ __('investigations.rca.ai_other_run', ['run' => '#'.$analysis->investigation_id]) }}</p>
                        @endif
                    </section>

                    {{-- All candidates --}}
                    <section class="ss-panel" aria-labelledby="hypotheses-title" data-testid="investigation-hypotheses">
                        <div class="ss-panel-head"><div><h2 id="hypotheses-title" class="ss-panel-title">{{ __('investigations.hypotheses_title') }}</h2><p class="ss-panel-sub">{{ __('investigations.hypotheses_text') }}</p></div></div>
                        @foreach ($r['hypotheses'] as $h)
                            <details class="mt-2 border-t border-line pt-2" @if ($loop->first) open @endif data-code="{{ $h['code'] }}">
                                <summary class="flex cursor-pointer flex-wrap items-center justify-between gap-2 text-sm">
                                    <span class="font-semibold text-fg">{{ $h['key'] }} · {{ $p->hypothesis($h) }}</span>
                                    <span class="ss-status {{ $confidenceTone($h['confidence']) }}">{{ __('investigations.confidence.'.$h['confidence']) }}</span>
                                </summary>
                                <p class="m-0 mt-1 text-sm text-muted">{{ $p->explanation($h) }}</p>
                                <p class="m-0 mt-1 text-xs text-muted">{{ implode(' · ', array_map(fn ($c) => __('investigations.reasons.'.$c), $h['reasons'])) }}</p>
                                @if (count($h['path']) > 1)<p class="m-0 mt-1 text-xs">{{ __('investigations.primary.path') }}: {{ $p->path($h['path']) }}</p>@endif
                                <p class="m-0 mt-1 text-xs">
                                    {{ __('investigations.primary.supporting') }}: @forelse ($h['supporting'] as $k)<a class="ss-chip no-underline" href="#{{ $k }}">{{ $k }}</a> @empty — @endforelse
                                    · {{ __('investigations.primary.contradicting') }}: @forelse ($h['contradicting'] as $k)<a class="ss-chip no-underline" href="#{{ $k }}">{{ $k }}</a> @empty — @endforelse
                                </p>
                            </details>
                        @endforeach

                        @if ($r['rejected'] !== [])
                            <h3 class="m-0 mt-4 text-sm font-semibold">{{ __('investigations.rejected_title') }}</h3>
                            <p class="m-0 text-xs text-muted">{{ __('investigations.rejected_text') }}</p>
                            @foreach ($r['rejected'] as $h)
                                <p class="m-0 mt-1 text-sm" data-testid="investigation-rejected">• <span class="line-through">{{ $p->hypothesis($h) }}</span> — {{ __('investigations.rejected.'.$h['reason']) }}</p>
                            @endforeach
                        @endif
                    </section>

                    {{-- C. Timeline --}}
                    <section class="ss-panel" aria-labelledby="inv-timeline-title" data-testid="investigation-timeline">
                        <div class="ss-panel-head"><div><h2 id="inv-timeline-title" class="ss-panel-title">{{ __('investigations.timeline.title') }}</h2><p class="ss-panel-sub">{{ __('investigations.timeline.text') }}</p></div></div>
                        <ul class="ss-timeline">
                            @foreach ($r['evidence'] as $e)
                                @continue($e['at'] === null || ! ($e['kind'] === 'event' || $e['problem'] || in_array($e['kind'], ['maintenance', 'coverage'], true) || $e['code'] === 'reboot'))
                                <li @class([$eventTone($e)]) data-phase="{{ $e['phase'] }}">
                                    <div class="ss-timeline-when"><time datetime="{{ $e['at'] }}">{{ Time::dateTimeSeconds($p->at($e['at'])) }}</time> · {{ __('investigations.phase.'.$e['phase']) }} · {{ __('investigations.kinds.'.($e['kind'] === 'event' ? $e['category'] : $e['kind'])) }}@if ($e['resource']) · {{ $p->label($e['resource']) }}@endif</div>
                                    <div class="ss-timeline-what"><strong>{{ $p->evidence($e) }}</strong> <a class="ss-chip no-underline" href="#{{ $e['key'] }}">{{ $e['key'] }}</a></div>
                                    @if ($e['detail'])<div class="ss-timeline-body text-fg">{{ $e['detail'] }}</div>@endif
                                </li>
                            @endforeach
                            <li class="is-info" data-phase="run">
                                <div class="ss-timeline-when"><time datetime="{{ $inv->finished_at?->toIso8601String() }}">{{ Time::dateTimeSeconds($inv->finished_at) }}</time></div>
                                <div class="ss-timeline-what"><strong>{{ __('investigations.timeline.run') }}</strong></div>
                            </li>
                        </ul>
                    </section>

                    {{-- D. Impact --}}
                    <section class="ss-panel" aria-labelledby="impact-title" data-testid="investigation-impact">
                        <div class="ss-panel-head"><div><h2 id="impact-title" class="ss-panel-title">{{ __('investigations.impact.title') }}</h2><p class="ss-panel-sub">{{ __('investigations.impact.text', ['resource' => $p->label($r['impact']['cause']).(($r['impact']['via'] ?? null) ? ' '.__('investigations.impact.via', ['service' => $p->label($r['impact']['via'])]) : '')]) }}</p></div>
                            @if ($r['impact']['cause'])<a class="ss-btn ss-btn-ghost ss-btn-sm" href="{{ route('nexus.index', ['focus' => $r['impact']['cause']]) }}" data-testid="investigation-nexus">{{ __('investigations.nexus') }}</a>@endif
                        </div>
                        @if ($r['impact']['items'] === [])
                            <p class="m-0 text-sm text-muted">{{ __('investigations.impact.none') }}</p>
                        @else
                            <div class="ss-table-wrap">
                                <table class="ss-table ss-table-cards">
                                    <thead><tr><th scope="col">{{ __('investigations.impact.resource') }}</th><th scope="col">{{ __('investigations.impact.assessment') }}</th><th scope="col">{{ __('investigations.impact.current') }}</th><th scope="col">{{ __('investigations.impact.path') }}</th></tr></thead>
                                    <tbody>
                                        @foreach ($r['impact']['items'] as $i)
                                            <tr data-assessment="{{ $i['assessment'] }}">
                                                <td class="is-primary"><span class="font-semibold text-fg">{{ $i['label'] }}</span><span class="block text-xs text-muted">{{ $p->typeLabel($i['key']) }}</span></td>
                                                <td data-label="{{ __('investigations.impact.assessment') }}">
                                                    <x-status :status="['failing' => 'down', 'healthy' => 'up', 'unobserved' => 'unknown'][$i['assessment']]" :label="__('investigations.impact.'.$i['assessment'])" />
                                                    <span class="block text-xs text-muted">@if ($i['assessment'] === 'unobserved'){{ __('investigations.impact.unobserved_hint') }}@else{{ __('investigations.impact.observed', ['ok' => $i['observed']['ok'], 'failed' => $i['observed']['failed']]) }}@if ($i['observed']['incidents'] !== []) · {{ __('investigations.impact.incidents', ['ids' => '#'.implode(', #', $i['observed']['incidents'])]) }}@endif @endif</span>
                                                </td>
                                                <td data-label="{{ __('investigations.impact.current') }}">@if (isset($current[$i['key']]))<x-status :status="$current[$i['key']]" />@else<span class="text-xs text-muted">{{ __('investigations.impact.removed') }}</span>@endif</td>
                                                <td data-label="{{ __('investigations.impact.path') }}" class="text-xs">
                                                    @foreach ($i['path'] as $n => $k)
                                                        @if ($n > 0) <span class="text-muted">→ ({{ __('investigations.impact.edge.'.$i['edges'][$n - 1]) }})</span> @endif{{ $p->label($k) }}
                                                    @endforeach
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif

                        @if ($r['impact']['inferred'] !== [])
                            <h3 class="m-0 mt-4 text-sm font-semibold">{{ __('investigations.impact.inferred_title') }}</h3>
                            <p class="m-0 text-xs text-muted">{{ __('investigations.impact.inferred_text') }}</p>
                            @foreach ($r['impact']['inferred'] as $i)
                                <p class="m-0 mt-1 text-sm">• {{ $i['direction'] === 'upstream' ? __('investigations.impact.inferred_upstream', ['resource' => $p->typeLabel($i['type'].':'.$i['id']).' #'.$i['id']]) : __('investigations.impact.inferred_downstream', ['resource' => $p->typeLabel($i['type'].':'.$i['id']).' #'.$i['id']]) }}</p>
                            @endforeach
                        @endif
                    </section>

                    {{-- E. Evidence explorer --}}
                    <section class="ss-panel" id="evidence" aria-labelledby="explorer-title" data-testid="investigation-evidence">
                        <div class="ss-panel-head"><div><h2 id="explorer-title" class="ss-panel-title">{{ __('investigations.explorer.title') }}</h2><p class="ss-panel-sub">{{ __('investigations.explorer.text') }}</p></div></div>
                        <form method="GET" action="{{ url()->current() }}#evidence" class="mb-3 grid gap-3 sm:grid-cols-[1fr_1fr_1fr_auto] sm:items-end">
                            <div><label class="ss-label" for="f-resource">{{ __('investigations.explorer.filter_resource') }}</label>
                                <select id="f-resource" class="ss-input mt-1.5" name="resource"><option value="">{{ __('investigations.explorer.all') }}</option>
                                    @foreach (collect($r['evidence'])->pluck('resource')->filter()->unique() as $k)<option value="{{ $k }}" @selected(($filters['resource'] ?? null) === $k)>{{ $p->label($k) }}</option>@endforeach
                                </select></div>
                            <div><label class="ss-label" for="f-category">{{ __('investigations.explorer.filter_category') }}</label>
                                <select id="f-category" class="ss-input mt-1.5" name="category"><option value="">{{ __('investigations.explorer.all') }}</option>
                                    @foreach (['incident', 'change', 'anomaly', 'service', 'check', 'recovery', 'metric', 'coverage', 'checks', 'maintenance'] as $c)<option value="{{ $c }}" @selected(($filters['category'] ?? null) === $c)>{{ __('investigations.kinds.'.$c) }}</option>@endforeach
                                </select></div>
                            <div><label class="ss-label" for="f-phase">{{ __('investigations.explorer.filter_phase') }}</label>
                                <select id="f-phase" class="ss-input mt-1.5" name="phase"><option value="">{{ __('investigations.explorer.all') }}</option>
                                    @foreach (['before', 'during', 'after'] as $ph)<option value="{{ $ph }}" @selected(($filters['phase'] ?? null) === $ph)>{{ __('investigations.phase.'.$ph) }}</option>@endforeach
                                </select></div>
                            <div class="flex gap-2"><button class="ss-btn ss-btn-secondary" type="submit">{{ __('investigations.explorer.apply') }}</button><a class="ss-btn ss-btn-ghost" href="{{ url()->current() }}#evidence">{{ __('investigations.explorer.reset') }}</a></div>
                        </form>
                        @forelse ($p->filtered($filters) as $e)
                            @php $role = $p->role($e['key']); @endphp
                            <div class="mt-2 border-t border-line pt-2 text-sm" id="{{ $e['key'] }}" data-key="{{ $e['key'] }}">
                                <span class="ss-chip">{{ $e['key'] }}</span>
                                <span class="text-xs text-muted">{{ $e['at'] ? Time::dateTimeSeconds($p->at($e['at'])) : __('investigations.explorer.no_time') }} · {{ __('investigations.phase.'.$e['phase']) }} · {{ __('investigations.kinds.'.($e['kind'] === 'event' ? $e['category'] : $e['kind'])) }}@if ($e['resource']) · {{ $p->label($e['resource']) }}@endif</span>
                                @if ($role)<span @class(['ss-chip', 'ss-chip-ok' => $role === 'supporting', 'ss-chip-warn' => $role === 'contradicting'])>{{ __('investigations.explorer.'.$role) }}</span>@endif
                                <p class="m-0 mt-1 text-fg">{{ $p->evidence($e) }}</p>
                                @if ($e['detail'])<p class="m-0 text-xs text-muted">{{ $e['detail'] }}</p>@endif
                                @if ($url = $sourceUrl($e['source']))<p class="m-0 text-xs"><a href="{{ $url }}">{{ __('investigations.explorer.source') }}</a></p>@endif
                            </div>
                        @empty
                            <p class="m-0 text-sm text-muted">{{ __('investigations.explorer.none') }}</p>
                        @endforelse
                    </section>
                </div>

                <aside class="grid min-w-0 gap-4">
                    {{-- A. Overview --}}
                    <section class="ss-panel" aria-labelledby="overview-title" data-testid="investigation-overview">
                        <div class="ss-panel-head"><div><h2 id="overview-title" class="ss-panel-title">{{ __('investigations.overview.title') }}</h2></div></div>
                        <dl class="ss-dl">
                            <dt>{{ __('investigations.overview.status') }}</dt><dd><span class="ss-status {{ $statusTone($inv->status) }}">{{ __('investigations.status.'.$inv->status) }}</span></dd>
                            <dt>{{ __('investigations.overview.trigger') }}</dt><dd>{{ $trigger }}</dd>
                            <dt>{{ __('investigations.overview.as_of') }}</dt><dd>{{ Time::dateTimeSeconds($inv->as_of) }}</dd>
                            <dt>{{ __('investigations.overview.window') }}</dt><dd>{{ __('investigations.overview.window_value', ['from' => Time::dateTime($p->at($r['window']['from'])), 'to' => Time::dateTime($p->at($r['window']['to'])), 'onset' => Time::dateTimeSeconds($p->at($r['window']['onset']))]) }}@if ($r['window']['truncated'])<span class="block text-xs text-muted">{{ __('investigations.overview.window_truncated', ['hours' => ScopeResolver::MAX_SPAN_HOURS]) }}</span>@endif</dd>
                            <dt>{{ __('investigations.overview.completeness') }}</dt><dd>{{ $limitations === 0 ? __('investigations.overview.complete') : __('investigations.overview.incomplete', ['count' => $limitations]) }}</dd>
                            <dt>{{ __('investigations.overview.evidence_count') }}</dt><dd>{{ count($r['evidence']) }}</dd>
                            <dt>{{ __('investigations.overview.pipeline') }}</dt><dd>v{{ $inv->pipeline_version }}</dd>
                            <dt>{{ __('investigations.overview.finished') }}</dt><dd>{{ Time::dateTimeSeconds($inv->finished_at) }}</dd>
                            <dt>{{ __('investigations.overview.digest') }}</dt><dd class="font-mono text-xs break-all">{{ substr((string) $inv->input_digest, 0, 16) }}</dd>
                        </dl>
                    </section>

                    {{-- F. Next steps --}}
                    <section class="ss-panel" aria-labelledby="steps-title" data-testid="investigation-steps">
                        <div class="ss-panel-head"><div><h2 id="steps-title" class="ss-panel-title">{{ __('investigations.primary.steps') }}</h2><p class="ss-panel-sub">{{ __('investigations.primary.steps_text') }}</p></div></div>
                        @foreach ($primary['next_steps'] as $step)
                            <p class="m-0 mt-2 text-sm">• {{ $p->step($step) }}</p>
                        @endforeach
                    </section>

                    @if ($limitations > 0)
                        <section class="ss-panel" aria-labelledby="gaps-title" data-testid="investigation-gaps">
                            <div class="ss-panel-head"><div><h2 id="gaps-title" class="ss-panel-title">{{ __('investigations.gaps_title') }}</h2></div></div>
                            @foreach ($r['gaps'] as $gap)
                                <p class="m-0 mt-2 text-sm text-muted">{{ $p->gap($gap) }}</p>
                            @endforeach
                            @foreach ([...$r['truncated'], ...($r['impact']['truncated'] ? ['impact'] : [])] as $t)
                                <p class="m-0 mt-2 text-sm text-muted">{{ in_array($t, ['scope', 'evidence', 'metrics', 'impact'], true) ? __('investigations.truncated.'.$t) : __('investigations.truncated.other', ['name' => __('investigations.collectors.'.$t)]) }}</p>
                            @endforeach
                        </section>
                    @endif
                </aside>
            </div>
        @endif

        {{-- G. History --}}
        @if ($history->isNotEmpty())
            <section class="ss-panel" aria-labelledby="history-title" data-testid="investigation-history">
                <div class="ss-panel-head"><div><h2 id="history-title" class="ss-panel-title">{{ __('investigations.history.title') }}</h2><p class="ss-panel-sub">{{ __('investigations.history.text') }}</p></div></div>
                <div class="ss-table-wrap">
                    <table class="ss-table ss-table-cards">
                        <tbody>
                            @foreach ($history as $run)
                                @php $older = $history->get($loop->index + 1); @endphp
                                <tr>
                                    <td class="is-primary"><a class="ss-hit font-semibold text-fg no-underline hover:text-accent" href="{{ route('investigations.show', $run) }}">#{{ $run->id }} · {{ Time::dateTimeSeconds($run->created_at) }}</a>@if ($inv && $run->id === $inv->id) <span class="ss-chip">{{ __('investigations.history.current') }}</span>@endif</td>
                                    <td><span class="ss-status {{ $statusTone($run->status) }}">{{ __('investigations.status.'.$run->status) }}</span></td>
                                    <td class="text-sm">@if ($run->summary){{ __('investigations.hypotheses.'.$run->summary['primary']['code'].'.title', ['resource' => $run->summary['primary']['label'] ?? '—', 'id' => $run->summary['primary']['incident_id'] ?? '—']) }} · {{ __('investigations.confidence.'.$run->summary['primary']['confidence']) }}@else—@endif</td>
                                    <td class="text-xs text-muted">@if ($older && $run->input_digest && $older->input_digest){{ $run->input_digest === $older->input_digest ? __('investigations.history.same_input') : __('investigations.history.changed_input') }}@endif</td>
                                    <td class="text-xs" data-label="{{ __('investigations.rca.status_ai') }}">@if ($aiRun = $aiByRun->get($run->id)){{ __('investigations.rca.status_ai') }}: {{ __('investigations.rca.ai_state.'.$aiRun->status) }}@else<span class="text-muted">{{ __('investigations.rca.status_ai') }}: —</span>@endif</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif
    </div>
</x-layouts.app>
