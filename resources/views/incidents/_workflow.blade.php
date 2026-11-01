{{-- Investigate → cause → resolve → verify → close: derived from real state only (incident status, Incident Memory, live monitoring). --}}
@php
    $subject = $incident->subject;
    $health = match (true) {
        $subject instanceof \App\Models\Domain => \App\Support\Health::domain($subject),
        $subject instanceof \App\Models\Server => \App\Support\Health::server($subject),
        default => null,
    };
    $resolved = in_array($incident->status, ['resolved', 'closed'], true);
    $steps = [
        'investigate' => [in_array($incident->status, ['acknowledged', 'investigating', 'resolved', 'closed'], true), [
            [__('incidents.workflow.links.timeline'), '#timeline'],
            [__('time_machine.open'), route('incidents.time-machine', $incident)],
            [__('ai_analysis.title'), '#ai-analysis'],
        ]],
        'cause' => [$learning !== null, [[__('incident_memory.title'), '#memory']]],
        'resolve' => [$resolved, [[__('incidents.workflow.links.actions'), '#actions']]],
        'verify' => [$resolved && $health === 'healthy', $subject ? [[__('incidents.workflow.links.resource'), \App\Services\Dependencies\ResourceCatalog::url($subject) ?? '#']] : []],
        'close' => [$incident->status === 'closed' && $learning !== null && $learning->outcome !== 'unresolved', [[__('incident_memory.record'), '#memory']]],
    ];
    $current = array_key_first(array_filter($steps, fn ($s) => ! $s[0]));
@endphp
<section class="ss-panel" id="workflow" aria-labelledby="workflow-title" data-testid="incident-workflow">
    <div class="ss-panel-head"><div><h2 id="workflow-title" class="ss-panel-title">{{ __('incidents.workflow.title') }}</h2><p class="ss-panel-sub">{{ __('incidents.workflow.text') }}</p></div></div>
    <ol class="m-0 grid list-none gap-3 p-0 sm:grid-cols-5">
        @foreach ($steps as $key => [$done, $links])
            <li class="rounded-lg border border-line p-3" data-step="{{ $key }}" data-state="{{ $done ? 'done' : ($key === $current ? 'current' : 'todo') }}" @if ($key === $current) aria-current="step" @endif>
                <p class="m-0 text-xs text-muted">{{ $loop->iteration }} · {{ $done ? __('incidents.workflow.done') : ($key === $current ? __('incidents.workflow.current') : __('incidents.workflow.todo')) }}</p>
                <p class="m-0 mt-1 text-sm font-semibold">@if ($done)<svg class="ico" aria-hidden="true"><use href="#i-check"/></svg> @endif{{ __('incidents.workflow.steps.'.$key) }}</p>
                @if ($key === 'cause' && $learning)
                    <p class="m-0 mt-1 text-xs">{{ __('incident_memory.cause_status.'.$learning->cause_status) }}</p>
                @endif
                @if ($key === 'verify' && $health)
                    <p class="m-0 mt-1 text-xs"><x-health :state="$health" /></p>
                @endif
                @foreach ($links as [$text, $href])
                    <a class="mt-1 block text-xs" href="{{ $href }}">{{ $text }}</a>
                @endforeach
            </li>
        @endforeach
    </ol>
    <p class="m-0 mt-3 text-xs text-muted">{{ __('incidents.workflow.note') }}</p>
</section>
