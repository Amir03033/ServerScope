{{-- The postmortem as a document. $editing: textareas for the written parts
     (inside the page's form). $public: the shared, read-only page (own h1). --}}
@php
    use App\Services\Incidents\PostmortemBuilder;
    use App\Support\StatusLabel;
    use App\Support\Time;

    $incident = $pm['incident'];
    $facts = $pm['facts'];
    $editing ??= false;
    $public ??= false;
    $n = 0;
    $paragraphs = fn (?string $text) => collect(preg_split('/\n{2,}/', trim((string) $text)))->filter();
@endphp
<article class="ss-doc" aria-label="{{ __('postmortems.title') }}">
    <header class="ss-doc-head">
        <p class="ss-doc-kicker">{{ __('postmortems.kicker', ['id' => $incident->id]) }}</p>
        @if ($public)
            <h1 class="ss-doc-title">{{ $incident->title }}</h1>
        @endif
        <div class="ss-doc-meta">
            <x-severity :severity="$incident->severity" />
            <span>{{ StatusLabel::for($incident->status) }}</span>
            <span aria-hidden="true">·</span>
            <span>{{ $pm['subject'] }}</span>
            <span aria-hidden="true">·</span>
            <span>{{ Time::date($facts['started']) }}</span>
        </div>
    </header>

    <dl class="ss-doc-facts">
        <div><dt>{{ __('postmortems.facts.duration') }}</dt><dd>{{ $facts['ongoing'] ? __('postmortems.ongoing') : PostmortemBuilder::duration($facts['duration']) }}</dd></div>
        <div><dt>{{ __('postmortems.facts.time_to_detect') }}</dt><dd>{{ PostmortemBuilder::duration($facts['time_to_detect']) }}</dd></div>
        <div><dt>{{ __('postmortems.facts.time_to_acknowledge') }}</dt><dd>{{ $facts['acknowledged'] ? PostmortemBuilder::duration($facts['time_to_acknowledge']) : __('postmortems.facts.not_yet') }}</dd></div>
        <div><dt>{{ __('postmortems.facts.resolved') }}</dt><dd>{{ $facts['resolved'] ? Time::dateTime($facts['resolved']) : __('postmortems.facts.not_yet') }}</dd></div>
    </dl>

    {{-- Summary --}}
    <section class="ss-doc-section" aria-labelledby="pm-summary">
        <h2 id="pm-summary"><span class="ss-doc-num">{{ ++$n }}</span>{{ __('postmortems.sections.summary') }}</h2>
        @if ($editing)
            <label class="sr-only" for="pm-summary-field">{{ __('postmortems.sections.summary') }}</label>
            <textarea id="pm-summary-field" name="summary" class="ss-input ss-doc-field" rows="4" maxlength="5000" placeholder="{{ __('postmortems.summary_placeholder') }}" data-autosize>{{ old('summary', $pm['summary_is_default'] ? '' : $pm['summary']) }}</textarea>
            @if ($pm['summary_is_default'])<p class="ss-doc-hint">{{ $pm['summary'] }}</p>@endif
        @else
            @foreach ($paragraphs($pm['summary']) as $paragraph)<p @class(['ss-doc-lead' => $loop->first])>{{ $paragraph }}</p>@endforeach
        @endif
    </section>

    {{-- Impact --}}
    <section class="ss-doc-section" aria-labelledby="pm-impact">
        <h2 id="pm-impact"><span class="ss-doc-num">{{ ++$n }}</span>{{ __('postmortems.sections.impact') }}</h2>
        @if ($editing)
            <label class="sr-only" for="pm-impact-field">{{ __('postmortems.sections.impact') }}</label>
            <textarea id="pm-impact-field" name="impact" class="ss-input ss-doc-field" rows="3" maxlength="5000" placeholder="{{ __('postmortems.impact_placeholder') }}" data-autosize>{{ old('impact', $pm['impact']) }}</textarea>
        @else
            @foreach ($paragraphs($pm['impact']) as $paragraph)<p>{{ $paragraph }}</p>@endforeach
        @endif
        @if ($pm['impacts'] !== [])
            <ul class="ss-doc-impacts">
                @foreach ($pm['impacts'] as $impact)
                    <li>
                        <span class="ss-doc-impact-role" data-role="{{ $impact['role'] }}">{{ __('postmortems.impact_role.'.$impact['role']) }}</span>
                        <span class="min-w-0"><strong>{{ $impact['name'] }}</strong> <span class="text-muted">· {{ $impact['type'] }}</span></span>
                        <span class="ss-doc-impact-time">{{ $impact['to'] ? __('postmortems.impact_down', ['duration' => PostmortemBuilder::duration($impact['minutes'])]) : __('postmortems.impact_ongoing') }}</span>
                    </li>
                @endforeach
            </ul>
        @elseif (blank($pm['impact']) && ! $editing)
            <p class="ss-doc-empty">{{ __('postmortems.impact_none') }}</p>
        @endif
    </section>

    {{-- Timeline --}}
    <section class="ss-doc-section" aria-labelledby="pm-timeline">
        <h2 id="pm-timeline"><span class="ss-doc-num">{{ ++$n }}</span>{{ __('postmortems.sections.timeline') }}</h2>
        @if ($pm['timeline'] === [])
            <p class="ss-doc-empty">{{ __('postmortems.timeline.empty') }}</p>
        @else
            @php $lastDay = null; @endphp
            <ol class="ss-doc-timeline">
                @foreach ($pm['timeline'] as $row)
                    @php $day = Time::date($row['at']); @endphp
                    <li data-kind="{{ $row['kind'] }}">
                        <time datetime="{{ $row['at']->toIso8601String() }}">{{ Time::format($row['at'], 'H:i') }}@if ($day !== $lastDay)<span>{{ $day }}</span>@endif</time>
                        <div class="min-w-0"><strong>{{ $row['title'] }}</strong>@if ($row['detail'])<span>{{ $row['detail'] }}</span>@endif</div>
                    </li>
                    @php $lastDay = $day; @endphp
                @endforeach
            </ol>
        @endif
    </section>

    {{-- Root cause, from Incident Memory --}}
    <section class="ss-doc-section" aria-labelledby="pm-cause">
        <h2 id="pm-cause"><span class="ss-doc-num">{{ ++$n }}</span>{{ __('postmortems.sections.cause') }}</h2>
        @if ($pm['cause'])
            <p class="ss-doc-tags"><span class="ss-chip">{{ $pm['cause']['category'] }}</span> <span @class(['ss-chip', 'ss-chip-ok' => $pm['cause']['confirmed']])>{{ $pm['cause']['confirmed'] ? __('postmortems.cause_confirmed') : __('postmortems.cause_hypothesis') }}</span></p>
            @foreach ($paragraphs($pm['cause']['text']) as $paragraph)<p>{{ $paragraph }}</p>@endforeach
            @if ($pm['cause']['symptoms'])<p class="ss-doc-small"><strong>{{ __('postmortems.symptoms') }}:</strong> {{ implode(', ', $pm['cause']['symptoms']) }}</p>@endif
            @if ($pm['deployment'])<p class="ss-doc-small">{{ __('postmortems.related_deployment', ['label' => $pm['deployment']->label()]) }}@if ($pm['deployment']->title) · {{ $pm['deployment']->title }}@endif</p>@endif
        @else
            <p class="ss-doc-empty">{{ __('postmortems.cause_missing') }}</p>
            @unless ($public)<a class="ss-btn ss-btn-secondary ss-btn-sm ss-noprint" href="{{ route('incidents.show', $incident) }}#memory">{{ __('postmortems.cause_record') }}</a>@endunless
        @endif
    </section>

    {{-- Resolution --}}
    <section class="ss-doc-section" aria-labelledby="pm-resolution">
        <h2 id="pm-resolution"><span class="ss-doc-num">{{ ++$n }}</span>{{ __('postmortems.sections.resolution') }}</h2>
        @if (filled($pm['resolution']))
            @foreach ($paragraphs($pm['resolution']) as $paragraph)<p class="whitespace-pre-line">{{ $paragraph }}</p>@endforeach
        @else
            <p class="ss-doc-empty">{{ __('postmortems.resolution_missing') }}</p>
        @endif
        @if ($pm['outcome'])<p class="ss-doc-small"><x-status :status="['successful' => 'up', 'unsuccessful' => 'down', 'unresolved' => 'unknown'][$pm['outcome']]" :label="__('postmortems.outcome.'.$pm['outcome'])" /></p>@endif
    </section>

    {{-- Lessons --}}
    <section class="ss-doc-section" aria-labelledby="pm-lessons">
        <h2 id="pm-lessons"><span class="ss-doc-num">{{ ++$n }}</span>{{ __('postmortems.sections.lessons') }}</h2>
        <div class="ss-doc-columns">
            @foreach (['went_well' => 'good', 'went_wrong' => 'better'] as $field => $tone)
                <div class="ss-doc-lesson" data-tone="{{ $tone }}">
                    <h3>{{ __('postmortems.sections.'.$field) }}</h3>
                    @if ($editing)
                        <label class="sr-only" for="pm-{{ $field }}">{{ __('postmortems.sections.'.$field) }}</label>
                        <textarea id="pm-{{ $field }}" name="{{ $field }}" class="ss-input ss-doc-field" rows="4" maxlength="5000" placeholder="{{ __('postmortems.'.$field.'_placeholder') }}" data-autosize>{{ old($field, $pm[$field]) }}</textarea>
                    @elseif (filled($pm[$field]))
                        @foreach ($paragraphs($pm[$field]) as $paragraph)<p class="whitespace-pre-line">{{ $paragraph }}</p>@endforeach
                    @else
                        <p class="ss-doc-empty">{{ __('postmortems.lessons_empty') }}</p>
                    @endif
                </div>
            @endforeach
        </div>
    </section>

    {{-- Action items --}}
    <section class="ss-doc-section" aria-labelledby="pm-actions">
        <h2 id="pm-actions"><span class="ss-doc-num">{{ ++$n }}</span>{{ __('postmortems.sections.actions') }}</h2>
        @if ($editing)
            @php $items = old('action_items', $pm['action_items']); $items = array_values(array_merge($items, $items === [] ? [['text' => '', 'owner' => '', 'done' => false]] : [])); @endphp
            <div class="ss-doc-action-editor" data-action-items>
                <div class="ss-doc-action-row ss-doc-action-headrow" aria-hidden="true"><span>{{ __('postmortems.action_done') }}</span><span>{{ __('postmortems.action_text') }}</span><span>{{ __('postmortems.action_owner') }}</span><span></span></div>
                @foreach ($items as $i => $item)
                    @include('incidents._postmortem-action-row', ['i' => $i, 'item' => $item])
                @endforeach
                <template data-action-template>@include('incidents._postmortem-action-row', ['i' => '__INDEX__', 'item' => ['text' => '', 'owner' => '', 'done' => false]])</template>
                <button type="button" class="ss-btn ss-btn-ghost ss-btn-sm" data-action-add><svg class="ico"><use href="#i-plus"/></svg> {{ __('postmortems.add_action') }}</button>
            </div>
        @elseif ($pm['action_items'] === [])
            <p class="ss-doc-empty">{{ __('postmortems.actions_empty') }}</p>
        @else
            <ul class="ss-doc-checklist">
                @foreach ($pm['action_items'] as $item)
                    <li @class(['is-done' => $item['done']])>
                        <span class="ss-doc-check" aria-hidden="true">@if ($item['done'])<svg class="ico"><use href="#i-check"/></svg>@endif</span>
                        <span class="min-w-0"><span class="sr-only">{{ $item['done'] ? __('postmortems.action_done').': ' : '' }}</span>{{ $item['text'] }}@if ($item['owner'])<span class="ss-doc-owner">{{ $item['owner'] }}</span>@endif</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</article>
