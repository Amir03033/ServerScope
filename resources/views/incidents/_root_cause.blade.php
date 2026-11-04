{{-- Root cause analysis: the one place on the incident page that explains the cause. The AI interpretation and the deterministic investigation (NEXUS Intelligence) side by side; the full detail stays on the investigation page. --}}
@php
    use App\Services\Intelligence\Presenter;

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
    $chip = fn (string $k) => '<a class="ss-ai-ref" href="'.e($runUrl($k)).'" aria-label="'.e(__('investigations.rca.evidence_link', ['key' => $k])).'">'.e($k).'</a>';
    // What we know: the evidence behind the leading hypothesis, else the first problems observed.
    $facts = $p ? array_slice(array_values(array_filter(array_map(fn ($k) => $p->item($k), $leading ? $primary['supporting'] : [])) ?: array_filter($r['evidence'], fn ($e) => $e['problem'] && $e['at'] !== null)), 0, 3) : [];
    $alternatives = $p ? array_slice(array_values(array_filter($r['hypotheses'], fn ($h) => $h['key'] !== $primary['key'] && $h['code'] !== 'insufficient_evidence')), 0, 3) : [];
    $uncertain = $p ? [...array_map(fn ($m) => __('investigations.missing.'.$m), $primary['missing']), ...array_map(fn ($g) => $p->gap($g), $r['gaps'])] : [];
    $uncertain = array_slice(array_values(array_unique([...$uncertain, ...($ai['uncertainties'] ?? []), ...($ai['limitations'] ?? [])])), 0, 5);
    $aiSteps = $ai && $ai['next_steps'] !== [];
@endphp
<section class="ss-ai" id="root-cause" aria-labelledby="root-cause-title" data-testid="root-cause">
    <div class="ss-ai-head">
        <span class="ss-ai-mark" aria-hidden="true"><svg class="ico"><use href="#i-sparkles"/></svg></span>
        <div class="min-w-0 flex-1">
            <h2 id="root-cause-title" class="ss-ai-title">{{ __('investigations.rca.title') }}</h2>
            <p class="ss-ai-sub">{{ __('investigations.rca.text') }}</p>
        </div>
        @if ($isAdmin && ! $run?->isPending())
            <form method="POST" action="{{ route('investigations.store', $incident) }}">@csrf<button class="ss-btn ss-btn-secondary ss-btn-sm" type="submit"><svg class="ico"><use href="#i-refresh"/></svg> {{ $run ? __('investigations.rerun') : __('investigations.run') }}</button></form>
        @endif
    </div>

    {{-- Status of both layers, in words (not colour alone). --}}
    <p class="ss-ai-status" data-testid="root-cause-status">
        <span>{{ __('investigations.rca.status_rules') }}</span>
        <span class="ss-status {{ $statusTone($run?->status) }}">{{ $run ? __('investigations.status.'.$run->status) : __('investigations.rca.not_run') }}</span>
        <span class="ss-ai-status-gap">{{ __('investigations.rca.status_ai') }}</span>
        <span class="ss-status {{ $statusTone($analysis?->status) }}">{{ $analysis ? __('investigations.rca.ai_state.'.$analysis->status) : (! $aiAvailable ? __('investigations.rca.ai_off') : (! $aiPlan ? __('investigations.rca.ai_plan') : __('investigations.rca.not_run'))) }}</span>
    </p>

    @if (! $p)
        <div class="ss-ai-empty" @if ($run?->isPending()) role="status" @endif>
            @if ($run?->isPending())<span class="ss-ai-pulse" aria-hidden="true"></span>@endif
            <p class="m-0">{{ $run?->isPending() ? __('investigations.states.'.$run->status) : ($run?->status === 'failed' ? __('investigations.states.failed') : __('investigations.states.none')) }}</p>
            @if (! $run && $isAdmin)<p class="m-0 mt-1 text-xs text-muted">{{ __('investigations.states.none_admin') }}</p>@endif
        </div>
    @else
        <div class="ss-ai-verdicts">
            {{-- The AI's reading of the investigation. --}}
            @include('investigations._ai', ['analysis' => $analysis, 'deterministic' => $leading ? $primary : null, 'testid' => 'root-cause-ai'])

            {{-- The leading deterministic hypothesis with its evidence. --}}
            <article class="ss-ai-card is-rules" data-testid="root-cause-rules" data-code="{{ $primary['code'] }}">
                <p class="ss-ai-kicker"><svg class="ico" aria-hidden="true"><use href="#i-list"/></svg> {{ __('investigations.rca.deterministic') }}</p>
                <h3 class="ss-ai-verdict">{{ $p->hypothesis($primary) }}</h3>
                <p class="ss-ai-confidence">
                    <span class="ss-meter" data-level="{{ $primary['confidence'] }}" aria-hidden="true"><i></i><i></i><i></i></span>
                    {{ __('investigations.rca.rules_confidence', ['level' => __('investigations.confidence.'.$primary['confidence'])]) }}
                </p>
                <p class="ss-ai-text">{{ $leading ? $p->explanation($primary) : __('investigations.primary.none') }}</p>
                @if ($leading)
                    <dl class="ss-ai-evidence">
                        <dt>{{ __('investigations.primary.supporting') }}</dt><dd>@forelse ($primary['supporting'] as $k){!! $chip($k) !!} @empty — @endforelse</dd>
                        <dt>{{ __('investigations.primary.contradicting') }}</dt><dd>@forelse ($primary['contradicting'] as $k){!! $chip($k) !!} @empty — @endforelse</dd>
                    </dl>
                @endif
                @if ($alternatives !== [])
                    <div class="ss-ai-alts">
                        <p class="ss-ai-label">{{ __('investigations.rca.alternatives') }}</p>
                        <ul>
                            @foreach ($alternatives as $h)
                                <li><span>{{ $p->hypothesis($h) }}</span><span class="ss-meter" data-level="{{ $h['confidence'] }}" title="{{ __('investigations.confidence.'.$h['confidence']) }}"><i></i><i></i><i></i></span><span class="sr-only">({{ __('investigations.confidence.'.$h['confidence']) }})</span></li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </article>
        </div>

        <div class="ss-ai-sections">
            <div class="ss-ai-section">
                <h3 class="ss-ai-label"><svg class="ico" aria-hidden="true"><use href="#i-pulse"/></svg> {{ __('investigations.rca.observations') }}</h3>
                @if ($facts === [])
                    <p class="m-0 text-sm text-muted">{{ __('investigations.primary.none_listed') }}</p>
                @else
                    <ul class="ss-ai-list">
                        @foreach ($facts as $e)
                            <li>{!! $chip($e['key']) !!} <span>{{ $p->evidence($e) }}</span></li>
                        @endforeach
                    </ul>
                @endif
            </div>

            <div class="ss-ai-section">
                <h3 class="ss-ai-label"><svg class="ico" aria-hidden="true"><use href="#i-search"/></svg> {{ __('investigations.rca.checks') }}</h3>
                <ol class="ss-ai-steps">
                    @if ($aiSteps)
                        @foreach (array_slice($ai['next_steps'], 0, 4) as $step)
                            <li><span>{{ $step['step'] }}</span> @foreach ($step['evidence'] as $k){!! $chip($k) !!} @endforeach</li>
                        @endforeach
                    @else
                        @foreach (array_slice($primary['next_steps'], 0, 4) as $step)
                            <li><span>{{ $p->step($step) }}</span></li>
                        @endforeach
                    @endif
                </ol>
                @if ($aiSteps)<p class="m-0 mt-2 text-xs text-subtle">{{ __('investigations.rca.checks_ai') }}</p>@endif
            </div>

            @if ($uncertain !== [] || $r['truncated'] !== [] || $finished->status === 'partial')
                <div class="ss-ai-section">
                    <h3 class="ss-ai-label"><svg class="ico" aria-hidden="true"><use href="#i-alert"/></svg> {{ __('investigations.rca.uncertain') }}</h3>
                    <ul class="ss-ai-list is-muted">
                        @if ($finished->status === 'partial')<li>{{ __('investigations.states.partial') }}</li>@endif
                        @foreach ($uncertain as $line)
                            <li>{{ $line }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>

        <p class="ss-ai-caveat"><svg class="ico" aria-hidden="true"><use href="#i-shield"/></svg> {{ __('investigations.rca.caveat') }}</p>
    @endif

    <nav class="ss-ai-links" aria-label="{{ __('investigations.rca.title') }}">
        @if ($run)<a href="{{ route('investigations.show', $finished ?? $run) }}">{{ __('investigations.panel.view') }} <span aria-hidden="true">→</span></a>@endif
        @if ($p)<a href="{{ route('investigations.show', $finished) }}#evidence">{{ __('investigations.explorer.title') }}</a>@endif
        <a href="{{ route('incidents.time-machine', $incident) }}">{{ __('time_machine.open') }}</a>
        @if ($p && $r['impact']['cause'])<a href="{{ route('nexus.index', ['focus' => $r['impact']['cause']]) }}">{{ __('investigations.nexus') }}</a>@endif
    </nav>
</section>

{{-- Without a finished investigation the legacy AI analysis (if any) keeps its own panel: it is then the only explanation shown. --}}
@if (! $p && (! $analysis || ! $new))
    @include('incidents._ai_analysis')
@endif
