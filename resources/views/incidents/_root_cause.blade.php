{{-- Root cause analysis: the one place on the incident page that explains the cause. Deterministic investigation (NEXUS Intelligence) first, its AI interpretation next to it; the full detail stays on the investigation page. --}}
@php
    use App\Services\Intelligence\Presenter;
    use App\Support\Time;

    $run = $investigation;          // latest run (any status)
    $p = $finished ? new Presenter($finished) : null;
    $r = $p?->result() ?? [];
    $primary = $p?->primary();
    $leading = $primary && $primary['code'] !== 'insufficient_evidence';
    $isAdmin = auth()->user()->can('admin');
    $new = $analysis?->interpretsInvestigation() ?? false;
    $ai = $new ? $analysis->result : null;
    $statusTone = fn (?string $s) => match ($s) {
        'completed' => 'ss-status-ok', 'partial' => 'ss-status-warn', 'failed' => 'ss-status-bad', 'queued', 'running' => 'ss-status-info', default => 'ss-status-muted',
    };
    $runUrl = fn (string $key) => route('investigations.show', $finished).'#'.$key;
    // What we know: the evidence behind the leading hypothesis, else the first problems observed.
    $facts = $p ? array_slice(array_values(array_filter(array_map(fn ($k) => $p->item($k), $leading ? $primary['supporting'] : [])) ?: array_filter($r['evidence'], fn ($e) => $e['problem'] && $e['at'] !== null)), 0, 3) : [];
    $alternatives = $p ? array_slice(array_values(array_filter($r['hypotheses'], fn ($h) => $h['key'] !== $primary['key'] && $h['code'] !== 'insufficient_evidence')), 0, 3) : [];
    $uncertain = $p ? [...array_map(fn ($m) => __('investigations.missing.'.$m), $primary['missing']), ...array_map(fn ($g) => $p->gap($g), $r['gaps'])] : [];
    $uncertain = array_slice(array_values(array_unique([...$uncertain, ...($ai['uncertainties'] ?? []), ...($ai['limitations'] ?? [])])), 0, 5);
@endphp
<section class="ss-panel" id="root-cause" aria-labelledby="root-cause-title" data-testid="root-cause">
    <div class="ss-panel-head">
        <div>
            <h2 id="root-cause-title" class="ss-panel-title">{{ __('investigations.rca.title') }}</h2>
            <p class="ss-panel-sub">{{ __('investigations.rca.text') }}</p>
        </div>
        @if ($isAdmin && ! $run?->isPending())
            <form method="POST" action="{{ route('investigations.store', $incident) }}">@csrf<button class="ss-btn ss-btn-secondary ss-btn-sm" type="submit">{{ $run ? __('investigations.rerun') : __('investigations.run') }}</button></form>
        @endif
    </div>

    {{-- 8. Status of both layers, in words (not colour alone). --}}
    <p class="m-0 flex flex-wrap items-center gap-2 text-xs" data-testid="root-cause-status">
        <span>{{ __('investigations.rca.status_rules') }}:</span>
        <span class="ss-status {{ $statusTone($run?->status) }}">{{ $run ? __('investigations.status.'.$run->status) : __('investigations.rca.not_run') }}</span>
        <span>{{ __('investigations.rca.status_ai') }}:</span>
        <span class="ss-status {{ $statusTone($analysis?->status) }}">{{ $analysis ? __('investigations.rca.ai_state.'.$analysis->status) : (! $aiAvailable ? __('investigations.rca.ai_off') : (! $aiPlan ? __('investigations.rca.ai_plan') : __('investigations.rca.not_run'))) }}</span>
    </p>

    @if (! $p)
        <p class="m-0 mt-3 text-sm text-muted">{{ $run?->isPending() ? __('investigations.states.'.$run->status) : ($run?->status === 'failed' ? __('investigations.states.failed') : __('investigations.states.none')) }}</p>
    @else
        <div class="mt-3 grid gap-4 lg:grid-cols-2">
            <div class="grid min-w-0 content-start gap-3">
                {{-- 1. Confirmed observations. --}}
                <div>
                    <h3 class="m-0 text-sm font-semibold">{{ __('investigations.rca.observations') }}</h3>
                    @forelse ($facts as $e)
                        <p class="m-0 mt-1 text-sm break-words"><a class="ss-chip no-underline" href="{{ $runUrl($e['key']) }}" aria-label="{{ __('investigations.rca.evidence_link', ['key' => $e['key']]) }}">{{ $e['key'] }}</a> {{ $p->evidence($e) }}</p>
                    @empty
                        <p class="m-0 mt-1 text-sm text-muted">{{ __('investigations.primary.none_listed') }}</p>
                    @endforelse
                </div>

                {{-- 2 + 4. Leading deterministic hypothesis with its evidence. --}}
                <div class="rounded-lg border border-line p-3" data-testid="root-cause-rules" data-code="{{ $primary['code'] }}">
                    <p class="m-0 text-xs text-muted">{{ __('investigations.rca.deterministic') }}</p>
                    <p class="m-0 text-sm font-semibold text-fg break-words">{{ $p->hypothesis($primary) }}</p>
                    <p class="m-0 mt-1 text-xs"><span class="ss-status ss-status-muted">{{ __('investigations.rca.rules_confidence', ['level' => __('investigations.confidence.'.$primary['confidence'])]) }}</span></p>
                    <p class="m-0 mt-1 text-sm">{{ $leading ? $p->explanation($primary) : __('investigations.primary.none') }}</p>
                    @if ($leading)
                        <p class="m-0 mt-1 text-xs">{{ __('investigations.primary.supporting') }}: @forelse ($primary['supporting'] as $k)<a class="ss-chip no-underline" href="{{ $runUrl($k) }}" aria-label="{{ __('investigations.rca.evidence_link', ['key' => $k]) }}">{{ $k }}</a> @empty — @endforelse · {{ __('investigations.primary.contradicting') }}: @forelse ($primary['contradicting'] as $k)<a class="ss-chip no-underline" href="{{ $runUrl($k) }}" aria-label="{{ __('investigations.rca.evidence_link', ['key' => $k]) }}">{{ $k }}</a> @empty — @endforelse</p>
                    @endif
                </div>

                {{-- 5. Alternatives. --}}
                @if ($alternatives !== [])
                    <div>
                        <h3 class="m-0 text-sm font-semibold">{{ __('investigations.rca.alternatives') }}</h3>
                        @foreach ($alternatives as $h)
                            <p class="m-0 mt-1 text-sm break-words">• {{ $p->hypothesis($h) }} <span class="text-xs text-muted">({{ __('investigations.confidence.'.$h['confidence']) }})</span></p>
                        @endforeach
                    </div>
                @endif
            </div>

            <div class="grid min-w-0 content-start gap-3">
                {{-- 3. AI interpretation (or its state). --}}
                @include('investigations._ai', ['analysis' => $analysis, 'deterministic' => $leading ? $primary : null, 'testid' => 'root-cause-ai'])

                {{-- 6. Uncertainties and missing data. --}}
                @if ($uncertain !== [] || $r['truncated'] !== [] || $finished->status === 'partial')
                    <div>
                        <h3 class="m-0 text-sm font-semibold">{{ __('investigations.rca.uncertain') }}</h3>
                        @if ($finished->status === 'partial')<p class="m-0 mt-1 text-sm text-muted">• {{ __('investigations.states.partial') }}</p>@endif
                        @foreach ($uncertain as $line)
                            <p class="m-0 mt-1 text-sm text-muted break-words">• {{ $line }}</p>
                        @endforeach
                    </div>
                @endif

                {{-- 7. Read-only checks: the AI's when it answered, otherwise the rules'. --}}
                <div>
                    <h3 class="m-0 text-sm font-semibold">{{ __('investigations.rca.checks') }}</h3>
                    @if ($ai && $ai['next_steps'] !== [])
                        @foreach (array_slice($ai['next_steps'], 0, 4) as $step)
                            <p class="m-0 mt-1 text-sm break-words">• {{ $step['step'] }} @foreach ($step['evidence'] as $k)<a class="ss-chip no-underline" href="{{ $runUrl($k) }}" aria-label="{{ __('investigations.rca.evidence_link', ['key' => $k]) }}">{{ $k }}</a> @endforeach</p>
                        @endforeach
                        <p class="m-0 mt-1 text-xs text-muted">{{ __('investigations.rca.checks_ai') }}</p>
                    @else
                        @foreach (array_slice($primary['next_steps'], 0, 4) as $step)
                            <p class="m-0 mt-1 text-sm break-words">• {{ $p->step($step) }}</p>
                        @endforeach
                    @endif
                </div>
            </div>
        </div>

        <p class="m-0 mt-3 text-xs text-muted">{{ __('investigations.rca.caveat') }}</p>
    @endif

    <p class="m-0 mt-3 flex flex-wrap gap-2">
        @if ($run)<a class="ss-btn ss-btn-ghost ss-btn-sm" href="{{ route('investigations.show', $finished ?? $run) }}">{{ __('investigations.panel.view') }}</a>@endif
        @if ($p)<a class="ss-btn ss-btn-ghost ss-btn-sm" href="{{ route('investigations.show', $finished) }}#evidence">{{ __('investigations.explorer.title') }}</a>@endif
        <a class="ss-btn ss-btn-ghost ss-btn-sm" href="{{ route('incidents.time-machine', $incident) }}">{{ __('time_machine.open') }}</a>
        @if ($p && $r['impact']['cause'])<a class="ss-btn ss-btn-ghost ss-btn-sm" href="{{ route('nexus.index', ['focus' => $r['impact']['cause']]) }}">{{ __('investigations.nexus') }}</a>@endif
    </p>
</section>

{{-- Without a finished investigation the legacy AI analysis (if any) keeps its own panel: it is then the only explanation shown. --}}
@if (! $p && (! $analysis || ! $new))
    @include('incidents._ai_analysis')
@endif
