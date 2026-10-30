@php
    use App\Support\Time;

    $period = $input['period'] ?? '30d';
@endphp

<x-layouts.app :title="__('reports.title')">
    <x-slot:subtitle>{{ __('reports.subtitle') }}</x-slot:subtitle>
    <x-slot:actions>
        <a class="ss-btn ss-btn-secondary" href="{{ route('reports.print', $input) }}" target="_blank" rel="noopener"><svg class="ico"><use href="#i-file"/></svg> {{ __('reports.print') }}</a>
        <a class="ss-btn ss-btn-ghost" href="{{ route('reports.export', [...$input, 'kind' => 'incidents']) }}"><svg class="ico"><use href="#i-file"/></svg> {{ __('reports.export_incidents') }}</a>
        <a class="ss-btn ss-btn-ghost" href="{{ route('reports.export', $input) }}"><svg class="ico"><use href="#i-file"/></svg> {{ __('reports.export_availability') }}</a>
    </x-slot:actions>

    <section class="ss-panel mb-4">
        <form method="GET" action="{{ route('reports.index') }}" class="flex flex-wrap items-end gap-3">
            <div>
                <label class="ss-label" for="period">{{ __('reports.period') }}</label>
                <select id="period" class="ss-input mt-1.5" name="period" data-custom-toggle="custom-range">
                    @foreach (['7d', '30d', '90d', 'month', 'custom'] as $v)
                        <option value="{{ $v }}" @selected($period === $v)>{{ __('reports.periods.'.$v) }}</option>
                    @endforeach
                </select>
            </div>
            <div id="custom-range" class="contents" @if ($period !== 'custom') hidden @endif>
                <div><label class="ss-label" for="from">{{ __('reports.from') }}</label><input id="from" class="ss-input mt-1.5" name="from" type="datetime-local" value="{{ $input['from'] ?? '' }}"></div>
                <div><label class="ss-label" for="to">{{ __('reports.to') }}</label><input id="to" class="ss-input mt-1.5" name="to" type="datetime-local" value="{{ $input['to'] ?? '' }}"></div>
            </div>
            <div><label class="ss-label" for="target">{{ __('reports.target') }}</label><input id="target" class="ss-input mt-1.5 !w-28" name="target" type="number" step="0.01" min="90" max="100" value="{{ $report['target'] }}"></div>
            <button class="ss-btn ss-btn-primary" type="submit">{{ __('reports.show') }}</button>
        </form>
        <p class="mt-3 mb-0 text-xs text-muted">
            {{ __('reports.range', ['from' => Time::dateTime($report['from']), 'to' => Time::dateTime($report['to']), 'zone' => Time::zone()]) }}
            {!! __('reports.method', ['coverage' => '<em>'.e(__('reports.coverage_word')).'</em>']) !!}
        </p>
    </section>

    @include('reports._body')
</x-layouts.app>
