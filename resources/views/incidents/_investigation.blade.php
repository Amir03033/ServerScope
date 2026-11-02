{{-- NEXUS Intelligence: compact view of the latest deterministic investigation (full result on its own page). --}}
@php
    $inv = $investigation;
    $s = $inv?->summary;
    $isAdmin = auth()->user()->can('admin');
@endphp
<section class="ss-panel" id="investigation" aria-labelledby="investigation-title" data-testid="investigation-panel" data-status="{{ $inv?->status ?? 'none' }}">
    <div class="ss-panel-head">
        <div><h2 id="investigation-title" class="ss-panel-title">{{ __('investigations.panel.title') }}</h2><p class="ss-panel-sub">{{ __('investigations.panel.text') }}</p></div>
        @if ($isAdmin && ! $inv?->isPending())
            <form method="POST" action="{{ route('investigations.store', $incident) }}">@csrf<button class="ss-btn ss-btn-secondary ss-btn-sm" type="submit">{{ $inv ? __('investigations.rerun') : __('investigations.run') }}</button></form>
        @endif
    </div>

    @if ($inv === null)
        <p class="m-0 text-sm text-muted">{{ __('investigations.states.none') }}</p>
    @elseif ($inv->isPending())
        <p class="m-0 text-sm" role="status">{{ __('investigations.states.'.$inv->status) }}</p>
        <x-auto-refresh :seconds="15" />
    @elseif ($inv->status === 'failed')
        <p class="m-0 text-sm text-muted">{{ __('investigations.states.failed') }}</p>
    @elseif ($s)
        <p class="m-0 text-xs text-muted">{{ __('investigations.panel.leading') }}</p>
        <p class="m-0 text-sm font-semibold text-fg">{{ __('investigations.hypotheses.'.$s['primary']['code'].'.title', ['resource' => $s['primary']['label'] ?? '—', 'id' => $s['primary']['incident_id'] ?? '—']) }}</p>
        <p class="m-0 mt-1 text-sm">{{ __('investigations.confidence.label') }}: {{ __('investigations.confidence.'.$s['primary']['confidence']) }}@if ($s['gaps'] > 0 || $s['partial'] || $s['truncated']) · <span class="text-muted">{{ __('investigations.panel.limitations', ['count' => $s['gaps'] + ($s['truncated'] ? 1 : 0)]) }}</span>@endif</p>
        @if ($s['partial'])<p class="m-0 mt-1 text-xs text-muted">{{ __('investigations.states.partial') }}</p>@endif
    @endif

    @if ($inv)
        <p class="m-0 mt-3"><a class="ss-btn ss-btn-ghost ss-btn-sm" href="{{ route('investigations.show', $inv) }}">{{ __('investigations.panel.view') }}</a></p>
    @endif
</section>
