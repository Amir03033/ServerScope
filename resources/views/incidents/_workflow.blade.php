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
            [__('ai_analysis.title'), '#root-cause'],
        ]],
        'cause' => [$learning !== null, [[__('incident_memory.title'), '#memory']]],
        'resolve' => [$resolved, [[__('incidents.workflow.links.actions'), '#resolve']]],
        'verify' => [$resolved && $health === 'healthy', $subject ? [[__('incidents.workflow.links.resource'), \App\Services\Dependencies\ResourceCatalog::url($subject) ?? '#']] : []],
        'close' => [$incident->status === 'closed' && $learning !== null && $learning->outcome !== 'unresolved', [[__('incident_memory.record'), '#memory']]],
    ];
    $current = array_key_first(array_filter($steps, fn ($s) => ! $s[0]));
@endphp
<div class="ss-wf" id="workflow" data-testid="incident-workflow">
    <h2 class="ss-wf-title">{{ __('incidents.workflow.title') }}</h2>
    <ol class="ss-wf-steps">
        @foreach ($steps as $key => [$done, $links])
            <li class="ss-wf-step" data-step="{{ $key }}" data-state="{{ $done ? 'done' : ($key === $current ? 'current' : 'todo') }}" @if ($key === $current) aria-current="step" @endif>
                <span class="ss-wf-dot" aria-hidden="true">@if ($done)<svg class="ico"><use href="#i-check"/></svg>@else{{ $loop->iteration }}@endif</span>
                <span class="ss-wf-text">
                    <span class="ss-wf-name">{{ __('incidents.workflow.steps.'.$key) }}</span>
                    <span class="sr-only">({{ $done ? __('incidents.workflow.done') : ($key === $current ? __('incidents.workflow.current') : __('incidents.workflow.todo')) }})</span>
                    @if ($key === 'cause' && $learning)
                        <span class="ss-wf-note">{{ __('incident_memory.cause_status.'.$learning->cause_status) }}</span>
                    @endif
                    @if ($key === 'verify' && $health)
                        <span class="ss-wf-note"><x-health :state="$health" /></span>
                    @endif
                    @if ($key === $current && $links !== [])
                        <span class="ss-wf-links">
                            @foreach ($links as [$text, $href])
                                <a class="ss-chip-link" href="{{ $href }}">{{ $text }}</a>
                            @endforeach
                        </span>
                    @endif
                </span>
            </li>
        @endforeach
    </ol>
    <p class="ss-wf-foot">{{ __('incidents.workflow.note') }}</p>
</div>
