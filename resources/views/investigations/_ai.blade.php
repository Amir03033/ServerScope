{{-- AI interpretation of a NEXUS Intelligence investigation (contract nexus.investigation.v1), or the state of the AI analysis. Shared by the incident page and the investigation page. Everything from the model is printed escaped. --}}
@php
    use App\Support\Time;

    // $analysis: the latest analysis of the incident (any contract) or null; $deterministic: the leading deterministic hypothesis or null.
    $a = $analysis;
    $new = $a?->interpretsInvestigation() ?? false;
    $r = $new ? $a->result : null;
    $input = $new ? ($a->evidence ?? []) : [];
    $candidates = collect($input['candidates'] ?? [])->keyBy('id');
    $isAdmin = auth()->user()->can('admin');
    $aiPlan ??= true;
    // A stored analysis keeps showing (also after AI was switched off or the plan changed); otherwise why there is none.
    $aiStatus = $a?->status ?? (! $aiAvailable ? 'unavailable' : (! $aiPlan ? 'plan' : 'none'));
    $title = function (?string $id) use ($candidates) {
        $c = $id !== null ? $candidates->get($id) : null;

        return $c === null ? __('investigations.rca.ai_unknown') : __('investigations.hypotheses.'.$c['code'].'.title', ['resource' => $c['resource'] ?? '—', 'id' => $c['related_incident'] ?? '—']);
    };
    $chip = fn (string $key) => $a?->investigation_id ? '<a class="ss-ai-ref" href="'.e(route('investigations.show', $a->investigation_id).'#'.$key).'" aria-label="'.e(__('investigations.rca.evidence_link', ['key' => $key])).'">'.e($key).'</a>' : '<span class="ss-ai-ref">'.e($key).'</span>';
    $chips = fn (array $keys) => $keys === [] ? '—' : implode(' ', array_map($chip, $keys));
    $agrees = $r && $deterministic && ($r['leading']['code'] ?? null) === $deterministic['code'];
    $canRun = $aiAvailable && $aiPlan && $isAdmin && ! $a?->isPending();
@endphp
<article id="ai-analysis" class="ss-ai-card is-ai" data-testid="{{ $testid ?? 'investigation-ai' }}" data-ai-status="{{ $aiStatus }}">
    <div class="ss-ai-card-head">
        <p class="ss-ai-kicker"><svg class="ico" aria-hidden="true"><use href="#i-sparkles"/></svg> {{ __('investigations.rca.ai') }}@if ($a?->provider === 'demo') <span class="ss-badge ss-badge-accent">{{ __('demo.example_ai') }}</span>@endif</p>
        @if ($canRun && $a !== null)
            <form method="POST" action="{{ route('incidents.analysis', $incident) }}">@csrf<button class="ss-btn ss-btn-ghost ss-btn-sm" type="submit">{{ __('ai_analysis.rerun') }}</button></form>
        @endif
    </div>

    @if ($a === null && $aiStatus === 'unavailable')
        <p class="ss-ai-text text-muted">{{ __('ai_analysis.unavailable') }}</p>
    @elseif ($a === null && $aiStatus === 'plan')
        <p class="ss-ai-text text-muted">{{ __('ai_analysis.plan') }} @if ($isAdmin)<a class="underline" href="{{ route('billing.show') }}">{{ __('ai_analysis.plan_link') }}</a>@endif</p>
    @elseif ($a === null)
        <p class="ss-ai-text text-muted">{{ __('investigations.rca.ai_none') }}</p>
        @if ($canRun)
            <form method="POST" action="{{ route('incidents.analysis', $incident) }}" class="mt-3">@csrf<button class="ss-btn ss-btn-primary ss-ai-cta" type="submit"><svg class="ico"><use href="#i-sparkles"/></svg> {{ __('investigations.rca.ai_run') }}</button></form>
        @endif
    @elseif ($a->isPending())
        <div class="ss-ai-thinking" role="status">
            <span class="ss-ai-pulse" aria-hidden="true"></span>
            <p class="m-0 text-sm">{{ __('ai_analysis.pending') }}</p>
        </div>
        <span class="ss-skeleton" aria-hidden="true"></span><span class="ss-skeleton is-short" aria-hidden="true"></span>
        <x-auto-refresh :seconds="15" />
    @elseif ($a->status === 'failed')
        <p class="ss-ai-text text-muted" role="alert">{{ __('ai_analysis.failed.'.(in_array($a->error_code, ['unavailable', 'timeout', 'rate_limited', 'provider_error', 'invalid_response', 'queue_failure'], true) ? $a->error_code : 'provider_error')) }} {{ __('investigations.rca.ai_failed_rules') }}</p>
    @elseif (! $new)
        {{-- An earlier analysis made without the investigation (legacy contract). --}}
        <p class="ss-ai-text">{{ $a->result['summary'] ?? '' }}</p>
        <p class="m-0 mt-2 text-xs text-muted">{{ __('investigations.rca.ai_legacy') }}</p>
    @else
        <h3 class="ss-ai-verdict">{{ $title($r['leading']['candidate'] === 'unknown' ? null : $r['leading']['candidate']) }}</h3>
        <p class="ss-ai-confidence">
            <span class="ss-meter" data-level="{{ $r['confidence']['level'] }}" aria-hidden="true"><i></i><i></i><i></i></span>
            {{ __('investigations.rca.ai_confidence', ['level' => __('investigations.confidence.'.$r['confidence']['level'])]) }}
        </p>
        @if ($deterministic && $r['leading']['candidate'] !== 'unknown')
            <p @class(['ss-ai-agree', 'is-differs' => ! $agrees])><svg class="ico" aria-hidden="true"><use href="#{{ $agrees ? 'i-check' : 'i-swap' }}"/></svg> {{ $agrees ? __('investigations.rca.ai_agrees') : __('investigations.rca.ai_differs') }}</p>
        @endif
        <p class="ss-ai-summary">{{ $r['summary'] }}</p>
        @if ($r['leading']['explanation'])<p class="ss-ai-text">{{ $r['leading']['explanation'] }}</p>@endif
        @if ($r['confidence']['explanation'])<p class="ss-ai-text text-muted">{{ $r['confidence']['explanation'] }}</p>@endif
        <dl class="ss-ai-evidence">
            <dt>{{ __('investigations.primary.supporting') }}</dt><dd>{!! $chips($r['leading']['supporting']) !!}</dd>
            <dt>{{ __('investigations.primary.contradicting') }}</dt><dd>{!! $chips($r['leading']['contradicting']) !!}</dd>
        </dl>

        @if ($r['alternatives'] !== [])
            <div class="ss-ai-alts">
                <p class="ss-ai-label">{{ __('investigations.rca.ai_alternatives') }}</p>
                <ul>@foreach ($r['alternatives'] as $alt)<li><span>{{ $title($alt['candidate']) }}</span></li>@endforeach</ul>
            </div>
        @endif

        @if ($r['removed_references'] > 0)
            <p class="m-0 mt-2 text-xs text-muted" data-testid="ai-removed">{{ __('investigations.rca.removed', ['count' => $r['removed_references']]) }}</p>
        @endif

        <p class="ss-ai-meta">{{ __('investigations.rca.ai_meta', ['time' => Time::dateTime($a->completed_at), 'run' => $a->investigation_id ? '#'.$a->investigation_id : '—']) }}</p>
    @endif
</article>
