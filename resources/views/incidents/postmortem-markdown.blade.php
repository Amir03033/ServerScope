@php
    use App\Services\Incidents\PostmortemBuilder;
    use App\Support\StatusLabel;
    use App\Support\Time;

    // Plain text for a .md download (served as an attachment, never rendered as HTML).
    $i = $pm['incident'];
    $f = $pm['facts'];
    $line = fn (?string $text) => trim(preg_replace('/\r\n?/', "\n", (string) $text));
@endphp
# {!! __('postmortems.page_title', ['title' => $i->title]) !!}

**{!! __('postmortems.kicker', ['id' => $i->id]) !!}** · {!! ucfirst($i->severity) !!} · {!! StatusLabel::for($i->status) !!} · {!! $pm['subject'] !!} · {!! Time::date($f['started']) !!}

| {!! __('postmortems.facts.duration') !!} | {!! __('postmortems.facts.time_to_detect') !!} | {!! __('postmortems.facts.time_to_acknowledge') !!} | {!! __('postmortems.facts.resolved') !!} |
|---|---|---|---|
| {!! $f['ongoing'] ? __('postmortems.ongoing') : PostmortemBuilder::duration($f['duration']) !!} | {!! PostmortemBuilder::duration($f['time_to_detect']) !!} | {!! $f['acknowledged'] ? PostmortemBuilder::duration($f['time_to_acknowledge']) : __('postmortems.facts.not_yet') !!} | {!! $f['resolved'] ? Time::dateTime($f['resolved']) : __('postmortems.facts.not_yet') !!} |

## 1. {!! __('postmortems.sections.summary') !!}

{!! $line($pm['summary']) !!}

## 2. {!! __('postmortems.sections.impact') !!}

@if (filled($pm['impact']))
{!! $line($pm['impact']) !!}

@endif
@forelse ($pm['impacts'] as $impact)
- **{!! $impact['name'] !!}** ({!! $impact['type'] !!}, {!! __('postmortems.impact_role.'.$impact['role']) !!}): {!! $impact['to'] ? __('postmortems.impact_down', ['duration' => PostmortemBuilder::duration($impact['minutes'])]) : __('postmortems.impact_ongoing') !!}
@empty
@if (blank($pm['impact']))
{!! __('postmortems.impact_none') !!}
@endif
@endforelse

## 3. {!! __('postmortems.sections.timeline') !!}

@forelse ($pm['timeline'] as $row)
- **{!! Time::dateTime($row['at']) !!}** {!! $row['title'] !!}@if ($row['detail']) ({!! $row['detail'] !!})@endif

@empty
{!! __('postmortems.timeline.empty') !!}
@endforelse

## 4. {!! __('postmortems.sections.cause') !!}

@if ($pm['cause'])
*{!! $pm['cause']['category'] !!} · {!! $pm['cause']['confirmed'] ? __('postmortems.cause_confirmed') : __('postmortems.cause_hypothesis') !!}*

{!! $line($pm['cause']['text']) !!}
@else
{!! __('postmortems.cause_missing') !!}
@endif

## 5. {!! __('postmortems.sections.resolution') !!}

{!! filled($pm['resolution']) ? $line($pm['resolution']) : __('postmortems.resolution_missing') !!}

## 6. {!! __('postmortems.sections.lessons') !!}

### {!! __('postmortems.sections.went_well') !!}

{!! filled($pm['went_well']) ? $line($pm['went_well']) : __('postmortems.lessons_empty') !!}

### {!! __('postmortems.sections.went_wrong') !!}

{!! filled($pm['went_wrong']) ? $line($pm['went_wrong']) : __('postmortems.lessons_empty') !!}

## 7. {!! __('postmortems.sections.actions') !!}

@forelse ($pm['action_items'] as $item)
- [{!! $item['done'] ? 'x' : ' ' !!}] {!! $item['text'] !!}@if ($item['owner']) ({!! $item['owner'] !!})@endif

@empty
{!! __('postmortems.actions_empty') !!}
@endforelse
