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
    $chip = fn (string $key) => $a?->investigation_id ? '<a class="ss-chip no-underline" href="'.e(route('investigations.show', $a->investigation_id).'#'.$key).'" aria-label="'.e(__('investigations.rca.evidence_link', ['key' => $key])).'">'.e($key).'</a>' : '<span class="ss-chip">'.e($key).'</span>';
    $chips = fn (array $keys) => $keys === [] ? '—' : implode(' ', array_map($chip, $keys));
    $agrees = $r && $deterministic && ($r['leading']['code'] ?? null) === $deterministic['code'];
@endphp
<div id="ai-analysis" data-testid="{{ $testid ?? 'investigation-ai' }}" data-ai-status="{{ $aiStatus }}" class="grid gap-2">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <h3 class="m-0 text-sm font-semibold">{{ __('investigations.rca.ai') }}</h3>
        @if ($aiAvailable && $aiPlan && $isAdmin && ! $a?->isPending())
            <form method="POST" action="{{ route('incidents.analysis', $incident) }}">@csrf<button class="ss-btn ss-btn-ghost ss-btn-sm" type="submit">{{ $a ? __('ai_analysis.rerun') : __('investigations.rca.ai_run') }}</button></form>
        @endif
    </div>

    @if ($a === null && $aiStatus === 'unavailable')
        <p class="m-0 text-sm text-muted">{{ __('ai_analysis.unavailable') }}</p>
    @elseif ($a === null && $aiStatus === 'plan')
        <p class="m-0 text-sm text-muted">{{ __('ai_analysis.plan') }} @if ($isAdmin)<a class="underline" href="{{ route('billing.show') }}">{{ __('ai_analysis.plan_link') }}</a>@endif</p>
    @elseif ($a === null)
        <p class="m-0 text-sm text-muted">{{ __('investigations.rca.ai_none') }}</p>
    @elseif ($a->isPending())
        <p class="m-0 text-sm" role="status">{{ __('ai_analysis.pending') }}</p>
        <x-auto-refresh :seconds="15" />
    @elseif ($a->status === 'failed')
        <p class="m-0 text-sm text-muted" role="alert">{{ __('ai_analysis.failed.'.(in_array($a->error_code, ['unavailable', 'timeout', 'rate_limited', 'provider_error', 'invalid_response', 'queue_failure'], true) ? $a->error_code : 'provider_error')) }} {{ __('investigations.rca.ai_failed_rules') }}</p>
    @elseif (! $new)
        {{-- An earlier analysis made without the investigation (legacy contract). --}}
        <p class="m-0 text-sm text-fg">{{ $a->result['summary'] ?? '' }}</p>
        <p class="m-0 text-xs text-muted">{{ __('investigations.rca.ai_legacy') }}</p>
    @else
        <p class="m-0 text-sm text-fg">{{ $r['summary'] }}</p>
        <div class="rounded-lg border border-line p-3">
            <p class="m-0 text-xs text-muted">{{ __('investigations.rca.ai_leading') }}</p>
            <p class="m-0 text-sm font-semibold text-fg">{{ $title($r['leading']['candidate'] === 'unknown' ? null : $r['leading']['candidate']) }}</p>
            <p class="m-0 mt-1 text-xs">
                <span class="ss-status ss-status-muted">{{ __('investigations.rca.ai_confidence', ['level' => __('investigations.confidence.'.$r['confidence']['level'])]) }}</span>
                @if ($deterministic && $r['leading']['candidate'] !== 'unknown')
                    <span class="text-muted">· {{ $agrees ? __('investigations.rca.ai_agrees') : __('investigations.rca.ai_differs') }}</span>
                @endif
            </p>
            @if ($r['leading']['explanation'])<p class="m-0 mt-1 text-sm">{{ $r['leading']['explanation'] }}</p>@endif
            @if ($r['confidence']['explanation'])<p class="m-0 mt-1 text-xs text-muted">{{ $r['confidence']['explanation'] }}</p>@endif
            <p class="m-0 mt-1 text-xs">{{ __('investigations.primary.supporting') }}: {!! $chips($r['leading']['supporting']) !!} · {{ __('investigations.primary.contradicting') }}: {!! $chips($r['leading']['contradicting']) !!}</p>
        </div>

        @if ($r['alternatives'] !== [])
            <p class="m-0 text-xs text-muted">{{ __('investigations.rca.ai_alternatives') }}: {{ implode(' · ', array_map(fn ($alt) => $title($alt['candidate']), $r['alternatives'])) }}</p>
        @endif

        @if ($r['removed_references'] > 0)
            <p class="m-0 text-xs text-muted" data-testid="ai-removed">{{ __('investigations.rca.removed', ['count' => $r['removed_references']]) }}</p>
        @endif

        <p class="m-0 text-xs text-muted">{{ __('investigations.rca.ai_meta', ['time' => Time::dateTime($a->completed_at), 'run' => $a->investigation_id ? '#'.$a->investigation_id : '—']) }}</p>
    @endif
</div>
