@php
    use App\Support\Format;
    use App\Support\Time;

    $statusTone = ['regression' => 'is-bad', 'no_regression' => 'is-ok', 'pending' => 'is-info', 'insufficient_data' => 'is-warn', 'no_resources' => 'is-warn'];
    $verdictStatus = ['regression' => 'down', 'improvement' => 'up', 'no_change' => 'up', 'insufficient_data' => 'unknown'];
    $value = fn ($v, string $unit) => $v === null ? '—' : Format::number($v, 1).$unit;
@endphp

<x-layouts.app :title="__('deployments.show_title', ['label' => $deployment->label()])">
    <x-slot:breadcrumb><span>›</span> <a href="{{ route('deployments.index') }}">{{ __('deployments.title') }}</a> <span>›</span> #{{ $deployment->id }}</x-slot:breadcrumb>
    <x-slot:pageTitle>{{ $deployment->label() }}</x-slot:pageTitle>
    <x-slot:subtitle>{{ $deployment->environment }} · {{ Time::dateTimeSeconds($deployment->deployed_at) }}</x-slot:subtitle>
    <x-slot:actions>
        <a href="{{ route('deployments.index') }}" class="ss-btn ss-btn-ghost"><svg class="ico"><use href="#i-arrow-left"/></svg> {{ __('deployments.title') }}</a>
    </x-slot:actions>

    <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_22rem] lg:items-start">
        <div class="grid min-w-0 gap-4">
            <section class="ss-panel" aria-labelledby="judge-title" data-testid="deploy-judge">
                <div class="ss-panel-head"><div><h2 id="judge-title" class="ss-panel-title">{{ __('deployments.judge_title') }}</h2><p class="ss-panel-sub">{{ __('deployments.judge_text', ['minutes' => $analysis['window_minutes']]) }}</p></div></div>
                <ul class="ss-timeline"><li class="{{ $statusTone[$analysis['status']] ?? '' }}"><div class="ss-timeline-what"><strong>{{ __('deployments.status.'.$analysis['status']) }}</strong></div>
                    @if (isset($analysis['ready_at']))<div class="ss-timeline-body">{{ __('deployments.ready_at', ['time' => Time::dateTime($analysis['ready_at'])]) }}</div>@endif
                </li></ul>
                <div class="ss-alert ss-alert-info mt-3" role="note"><svg class="ico"><use href="#i-alert"/></svg><div>{{ __('deployments.caveat') }}</div></div>

                @if ($analysis['checks'] !== [])
                    <div class="ss-table-wrap mt-4">
                        <table class="ss-table ss-table-cards">
                            <thead><tr><th scope="col">{{ __('deployments.col_metric') }}</th><th scope="col">{{ __('deployments.col_before') }}</th><th scope="col">{{ __('deployments.col_after') }}</th><th scope="col">{{ __('deployments.col_verdict') }}</th></tr></thead>
                            <tbody>
                                @foreach ($analysis['checks'] as $check)
                                    <tr>
                                        <td class="is-primary">{{ __('deployments.metric.'.$check['metric']) }}<span class="block text-xs text-muted">{{ $check['resource'] }} · {{ $check['basis'] }}</span></td>
                                        <td data-label="{{ __('deployments.col_before') }}">{{ $value($check['before'], $check['unit']) }} <span class="text-xs text-muted">(n={{ $check['n_before'] }})</span></td>
                                        <td data-label="{{ __('deployments.col_after') }}">{{ $value($check['after'], $check['unit']) }} <span class="text-xs text-muted">(n={{ $check['n_after'] }})</span></td>
                                        <td data-label="{{ __('deployments.col_verdict') }}"><x-status :status="$verdictStatus[$check['verdict']] ?? 'unknown'" :label="__('deployments.verdict.'.$check['verdict'])" /></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                @foreach ($analysis['incidents'] as $item)
                    <p class="m-0 mt-3 text-sm">{{ __('deployments.new_incident', ['minutes' => $item['minutes'], 'resource' => $item['resource']]) }} <a href="{{ route('incidents.show', $item['incident']) }}">#{{ $item['incident']->id }} {{ $item['incident']->title }}</a></p>
                @endforeach

                @if ($analysis['overlaps']->isNotEmpty())
                    <p class="m-0 mt-3 text-sm text-muted">{{ __('deployments.overlaps', ['count' => $analysis['overlaps']->count()]) }}
                        @foreach ($analysis['overlaps'] as $other)<a href="{{ route('deployments.show', $other) }}">{{ $other->label() }}</a>@if (! $loop->last), @endif @endforeach
                    </p>
                @endif
            </section>
        </div>

        <aside class="grid min-w-0 gap-4">
            <section class="ss-panel" aria-labelledby="details-title">
                <div class="ss-panel-head"><div><h2 id="details-title" class="ss-panel-title">{{ __('deployments.details') }}</h2></div></div>
                <dl class="ss-dl">
                    <dt>{{ __('deployments.application') }}</dt><dd>{{ $deployment->application }}</dd>
                    <dt>{{ __('deployments.version') }}</dt><dd>{{ $deployment->version ?? '—' }}</dd>
                    <dt>{{ __('deployments.col_commit') }}</dt><dd class="font-mono text-xs [overflow-wrap:anywhere]">{{ $deployment->commit_sha ?? '—' }}</dd>
                    <dt>{{ __('deployments.source') }}</dt><dd>{{ $deployment->source ?? '—' }}@if ($deployment->actor) · {{ $deployment->actor }}@endif</dd>
                    @if ($deployment->url)<dt>{{ __('deployments.link') }}</dt><dd class="[overflow-wrap:anywhere]"><a href="{{ $deployment->url }}" rel="noopener noreferrer" target="_blank">{{ \Illuminate\Support\Str::limit($deployment->url, 60) }}</a></dd>@endif
                </dl>
            </section>
            <section class="ss-panel" aria-labelledby="resources-title">
                <div class="ss-panel-head"><div><h2 id="resources-title" class="ss-panel-title">{{ __('deployments.col_resources') }}</h2></div></div>
                @forelse ($analysis['resources'] as $resource)
                    @php $url = $resourceUrl($resource['model']); @endphp
                    <p class="m-0 mt-2 text-sm">@if ($url)<a href="{{ $url }}">{{ $resource['label'] }}</a>@else{{ $resource['label'] }}@endif</p>
                @empty
                    <p class="m-0 text-sm text-muted">{{ __('deployments.status.no_resources') }}</p>
                @endforelse
            </section>
        </aside>
    </div>
</x-layouts.app>
