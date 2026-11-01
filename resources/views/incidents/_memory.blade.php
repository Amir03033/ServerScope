{{-- Incident Memory: what was learned from this incident, and similar earlier ones of the same team. --}}
@php
    use App\Models\IncidentLearning;
    use App\Support\Time;

    $l = $learning;
    $outcomeTone = ['successful' => 'up', 'unsuccessful' => 'down', 'unresolved' => 'unknown'];
    $reasonText = fn (string $r) => __('incident_memory.reasons.'.$r);
@endphp
<section class="ss-panel" id="memory" aria-labelledby="memory-title" data-testid="incident-memory">
    <div class="ss-panel-head"><div><h2 id="memory-title" class="ss-panel-title">{{ __('incident_memory.title') }}</h2><p class="ss-panel-sub">{{ __('incident_memory.text') }}</p></div></div>

    @if ($l)
        <dl class="ss-dl">
            <dt>{{ __('incident_memory.cause') }}</dt>
            <dd><x-status :status="$l->isConfirmed() ? 'up' : 'unknown'" :label="__('incident_memory.cause_status.'.$l->cause_status)" />
                {{ __('incident_memory.categories.'.$l->cause_category) }}
                <span class="block whitespace-pre-line">{{ $l->root_cause }}</span></dd>
            @if ($l->symptoms)<dt>{{ __('incident_memory.symptoms') }}</dt><dd>@foreach ($l->symptoms as $symptom)<span class="ss-chip">{{ $symptom }}</span> @endforeach</dd>@endif
            @if ($l->resolution_steps)<dt>{{ __('incident_memory.resolution') }}</dt><dd class="whitespace-pre-line">{{ $l->resolution_steps }}</dd>@endif
            <dt>{{ __('incident_memory.outcome') }}</dt><dd><x-status :status="$outcomeTone[$l->outcome]" :label="__('incident_memory.outcomes.'.$l->outcome)" /></dd>
            @if ($l->time_to_resolve_minutes !== null)<dt>{{ __('incident_memory.time_to_resolve') }}</dt><dd>{{ __('incident_memory.minutes', ['n' => $l->time_to_resolve_minutes]) }}</dd>@endif
            @if ($l->deployment)<dt>{{ __('incident_memory.deployment') }}</dt><dd><a href="{{ route('deployments.show', $l->deployment) }}">{{ $l->deployment->label() }}</a></dd>@endif
            <dt>{{ __('incident_memory.recorded') }}</dt><dd>{{ $l->recorder?->name ?? __('incidents.system') }} · {{ Time::dateTime($l->updated_at) }}</dd>
        </dl>
    @else
        <p class="m-0 text-sm text-muted">{{ __('incident_memory.empty') }}</p>
    @endif

    <details class="mt-4" @if (! $l || $errors->hasAny(['cause_category', 'cause_status', 'root_cause', 'symptoms', 'resolution_steps', 'outcome', 'deployment_id'])) open @endif>
        <summary class="cursor-pointer text-sm font-semibold">{{ $l ? __('incident_memory.edit') : __('incident_memory.record') }}</summary>
        <form method="POST" action="{{ route('incidents.learning', $incident) }}" class="mt-3 grid gap-3">
            @csrf
            @if ($l)<input type="hidden" name="version" value="{{ $l->version }}">@endif
            <div class="grid gap-3 sm:grid-cols-2">
                <div>
                    <label class="ss-label" for="cause_category">{{ __('incident_memory.category') }}</label>
                    <select id="cause_category" class="ss-input mt-1.5" name="cause_category" required>
                        @foreach (IncidentLearning::CATEGORIES as $category)<option value="{{ $category }}" @selected(old('cause_category', $l?->cause_category ?? 'unknown') === $category)>{{ __('incident_memory.categories.'.$category) }}</option>@endforeach
                    </select>
                </div>
                <div>
                    <label class="ss-label" for="cause_status">{{ __('incident_memory.certainty') }}</label>
                    <select id="cause_status" class="ss-input mt-1.5" name="cause_status" required>
                        @foreach (IncidentLearning::CAUSE_STATUSES as $status)<option value="{{ $status }}" @selected(old('cause_status', $l?->cause_status ?? 'hypothesis') === $status)>{{ __('incident_memory.cause_status.'.$status) }}</option>@endforeach
                    </select>
                </div>
            </div>
            <div>
                <label class="ss-label" for="root_cause">{{ __('incident_memory.cause') }}</label>
                <textarea id="root_cause" class="ss-input mt-1.5 min-h-24" name="root_cause" required minlength="3" maxlength="5000">{{ old('root_cause', $l?->root_cause) }}</textarea>
                @error('root_cause')<p class="m-0 mt-1 text-xs text-bad">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="ss-label" for="symptoms">{{ __('incident_memory.symptoms') }}</label>
                <input id="symptoms" class="ss-input mt-1.5" name="symptoms" maxlength="700" value="{{ old('symptoms', implode(', ', $l?->symptoms ?? [])) }}" placeholder="{{ __('incident_memory.symptoms_placeholder') }}">
            </div>
            <div>
                <label class="ss-label" for="resolution_steps">{{ __('incident_memory.resolution') }}</label>
                <textarea id="resolution_steps" class="ss-input mt-1.5 min-h-24" name="resolution_steps" maxlength="10000">{{ old('resolution_steps', $l?->resolution_steps ?? $incident->resolution_notes) }}</textarea>
            </div>
            <div class="grid gap-3 sm:grid-cols-2">
                <div>
                    <label class="ss-label" for="outcome">{{ __('incident_memory.outcome') }}</label>
                    <select id="outcome" class="ss-input mt-1.5" name="outcome" required>
                        @foreach (IncidentLearning::OUTCOMES as $outcome)<option value="{{ $outcome }}" @selected(old('outcome', $l?->outcome ?? 'unresolved') === $outcome)>{{ __('incident_memory.outcomes.'.$outcome) }}</option>@endforeach
                    </select>
                </div>
                @if ($deploymentCandidates->isNotEmpty() || $l?->deployment)
                    <div>
                        <label class="ss-label" for="deployment_id">{{ __('incident_memory.deployment') }}</label>
                        <select id="deployment_id" class="ss-input mt-1.5" name="deployment_id"><option value="">—</option>
                            @foreach ($deploymentCandidates->when($l?->deployment && ! $deploymentCandidates->contains('id', $l->deployment_id), fn ($c) => $c->push($l->deployment)) as $deployment)
                                <option value="{{ $deployment->id }}" @selected((int) old('deployment_id', $l?->deployment_id) === $deployment->id)>{{ $deployment->label() }} · {{ Time::dateTime($deployment->deployed_at) }}</option>
                            @endforeach
                        </select>
                        @error('deployment_id')<p class="m-0 mt-1 text-xs text-bad">{{ $message }}</p>@enderror
                    </div>
                @endif
            </div>
            <div><button class="ss-btn ss-btn-primary" type="submit">{{ __('incident_memory.save') }}</button>
                <a class="ss-btn ss-btn-ghost" href="{{ route('incidents.time-machine', $incident) }}">{{ __('time_machine.open') }}</a></div>
        </form>
    </details>

    <h3 class="m-0 mt-5 text-sm font-semibold">{{ __('incident_memory.similar_title') }}</h3>
    @forelse ($similar as $match)
        <div class="mt-3 border-t border-line pt-3" data-similar="{{ $match['incident']->id }}">
            <p class="m-0 text-sm"><a href="{{ route('incidents.show', $match['incident']) }}">#{{ $match['incident']->id }} {{ $match['incident']->title }}</a>
                <span class="block text-xs text-muted">{{ Time::dateTime($match['incident']->opened_at) }} · {{ implode(' · ', array_map($reasonText, $match['reasons'])) }}@if ($match['symptoms']) · {{ implode(', ', $match['symptoms']) }}@endif</span></p>
            @if ($match['learning'])
                <p class="m-0 mt-1 text-sm"><x-status :status="$match['learning']->isConfirmed() ? 'up' : 'unknown'" :label="__('incident_memory.cause_status.'.$match['learning']->cause_status)" />
                    {{ __('incident_memory.categories.'.$match['learning']->cause_category) }}: {{ \Illuminate\Support\Str::limit($match['learning']->root_cause, 200) }}</p>
                @if ($match['learning']->resolution_steps)
                    <p class="m-0 mt-1 text-xs text-muted">{{ __('incident_memory.fix') }} ({{ __('incident_memory.outcomes.'.$match['learning']->outcome) }}): {{ \Illuminate\Support\Str::limit($match['learning']->resolution_steps, 200) }}</p>
                @endif
            @else
                <p class="m-0 mt-1 text-xs text-muted">{{ __('incident_memory.no_learning') }}</p>
            @endif
        </div>
    @empty
        <p class="m-0 mt-2 text-sm text-muted">{{ __('incident_memory.similar_none') }}</p>
    @endforelse
</section>
