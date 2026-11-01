{{-- AI Incident Investigator: latest analysis of this incident (AI-generated hypotheses, never confirmed causes). --}}
@php
    use App\Support\Time;

    $a = $analysis;
    $r = $a?->result;
    $ev = $a?->evidence ?? [];
    // Readable labels for cited evidence ids.
    $labels = [];
    foreach ($ev['evidence'] ?? [] as $item) {
        $labels[$item['id']] = $item['text'] ?? (($item['resource'] ?? '').' '.($item['metric'] ?? ''));
    }
    foreach ($ev['deployments'] ?? [] as $item) { $labels[$item['id']] = $item['label']; }
    foreach ($ev['probe_checks'] ?? [] as $item) { $labels[$item['id']] = __('probes.outcome.'.$item['outcome']); }
    foreach ($ev['history'] ?? [] as $item) { $labels[$item['id']] = '#'.$item['incident_id'].' '.$item['title']; }
    $ref = function (string $id) use ($labels) {
        if (preg_match('/^H(\d+)$/', $id, $m)) {
            return '<a class="ss-chip" href="'.e(route('incidents.show', (int) $m[1])).'" title="'.e($labels[$id] ?? '').'">'.e($id).'</a>';
        }

        return '<span class="ss-chip" title="'.e($labels[$id] ?? '').'">'.e($id).'</span>';
    };
    $refs = fn (array $ids) => implode(' ', array_map($ref, $ids));
    $isAdmin = auth()->user()->can('admin');
@endphp
<section class="ss-panel" id="ai-analysis" aria-labelledby="ai-title" data-testid="ai-analysis" data-status="{{ ! $aiAvailable ? 'unavailable' : ($a?->status ?? 'none') }}">
    <div class="ss-panel-head">
        <div><h2 id="ai-title" class="ss-panel-title">{{ __('ai_analysis.title') }}</h2><p class="ss-panel-sub">{{ __('ai_analysis.text') }}</p></div>
        @if ($aiAvailable && $isAdmin && ! $a?->isPending())
            <form method="POST" action="{{ route('incidents.analysis', $incident) }}">@csrf<button class="ss-btn ss-btn-secondary ss-btn-sm" type="submit">{{ $a ? __('ai_analysis.rerun') : __('ai_analysis.run') }}</button></form>
        @endif
    </div>

    @if (! $aiAvailable)
        <p class="m-0 text-sm text-muted">{{ __('ai_analysis.unavailable') }}</p>
    @elseif ($a === null)
        <p class="m-0 text-sm text-muted">{{ __('ai_analysis.empty') }}</p>
    @elseif ($a->isPending())
        <p class="m-0 text-sm" role="status">{{ __('ai_analysis.pending') }}</p>
        <x-auto-refresh :seconds="15" />
    @elseif ($a->status === 'failed')
        <div class="ss-alert ss-alert-error" role="alert"><svg class="ico"><use href="#i-alert"/></svg><div>{{ __('ai_analysis.failed.'.($a->error_code ?? 'provider_error')) }}</div></div>
    @endif

    @if ($r)
        <div class="ss-alert ss-alert-info mt-3" role="note"><svg class="ico"><use href="#i-alert"/></svg><div>{{ __('ai_analysis.caveat') }}</div></div>
        <p class="m-0 mt-3 text-sm text-fg">{{ $r['summary'] }}</p>
        <p class="m-0 mt-2 text-sm"><strong>{{ __('ai_analysis.confidence') }}:</strong> {{ __('ai_analysis.levels.'.$r['confidence']['level']) }}@if ($r['confidence']['explanation']) — {{ $r['confidence']['explanation'] }}@endif</p>

        <h3 class="m-0 mt-4 text-sm font-semibold">{{ __('ai_analysis.facts') }}</h3>
        @forelse ($r['observed_facts'] as $fact)
            <p class="m-0 mt-1 text-sm">• {{ $fact['statement'] }} {!! $refs($fact['evidence']) !!}</p>
        @empty
            <p class="m-0 mt-1 text-sm text-muted">{{ __('ai_analysis.none') }}</p>
        @endforelse

        <h3 class="m-0 mt-4 text-sm font-semibold">{{ __('ai_analysis.causes') }}</h3>
        @forelse ($r['root_causes'] as $cause)
            <div class="mt-2">
                <p class="m-0 text-sm"><strong>{{ $cause['title'] }}</strong> <span class="text-xs text-muted">({{ __('ai_analysis.plausibility.'.$cause['plausibility']) }})</span></p>
                @if ($cause['explanation'])<p class="m-0 text-sm text-muted">{{ $cause['explanation'] }}</p>@endif
                @if ($cause['supporting'])<p class="m-0 text-xs">{{ __('ai_analysis.supporting') }}: {!! $refs($cause['supporting']) !!}</p>@endif
                @if ($cause['contradicting'])<p class="m-0 text-xs">{{ __('ai_analysis.contradicting') }}: {!! $refs($cause['contradicting']) !!}</p>@endif
            </div>
        @empty
            <p class="m-0 mt-1 text-sm text-muted">{{ __('ai_analysis.none') }}</p>
        @endforelse

        @if ($r['historical_references'])
            <h3 class="m-0 mt-4 text-sm font-semibold">{{ __('ai_analysis.history') }}</h3>
            @foreach ($r['historical_references'] as $h)
                @php $past = collect($ev['history'] ?? [])->firstWhere('incident_id', $h['incident_id']); @endphp
                <p class="m-0 mt-1 text-sm"><a href="{{ route('incidents.show', $h['incident_id']) }}">#{{ $h['incident_id'] }} {{ $past['title'] ?? '' }}</a> — {{ $h['relevance'] }}
                    @if ($past['recorded_learning'] ?? null)<span class="block text-xs text-muted">{{ __('incident_memory.cause_status.'.$past['recorded_learning']['cause_status']) }} · {{ __('incident_memory.fix') }}: {{ __('incident_memory.outcomes.'.$past['recorded_learning']['fix_outcome']) }}</span>@endif</p>
            @endforeach
        @endif

        @if ($r['investigation_steps'])
            <h3 class="m-0 mt-4 text-sm font-semibold">{{ __('ai_analysis.steps') }}</h3>
            <ol class="m-0 mt-1 pl-5 text-sm">@foreach ($r['investigation_steps'] as $step)<li>{{ $step }}</li>@endforeach</ol>
        @endif

        @if ($r['missing_information'])
            <h3 class="m-0 mt-4 text-sm font-semibold">{{ __('ai_analysis.missing') }}</h3>
            @foreach ($r['missing_information'] as $missing)<p class="m-0 mt-1 text-sm text-muted">• {{ $missing }}</p>@endforeach
        @endif

        <p class="m-0 mt-4 text-xs text-muted">{{ __('ai_analysis.meta', ['time' => Time::dateTime($a->completed_at), 'trigger' => __('ai_analysis.triggers.'.$a->trigger)]) }} {{ __('ai_analysis.record_hint') }}</p>
    @endif
</section>
