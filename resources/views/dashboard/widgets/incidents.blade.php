@php
    use App\Support\Time;
@endphp
<section class="ss-panel">
    <div class="ss-panel-head">
        <div><h2 class="ss-panel-title">{{ __('dashboard.overview.active_incidents') }}</h2><p class="ss-panel-sub">{{ __('dashboard.overview.critical_first') }}</p></div>
        <a class="ss-btn ss-btn-secondary ss-btn-sm" href="{{ route('incidents.index') }}">{{ __('dashboard.overview.all') }}</a>
    </div>
    @forelse ($inc['list'] as $incident)
        <a href="{{ route('incidents.show', $incident) }}" class="ss-row">
            <x-severity :severity="$incident->severity" />
            <span class="ss-row-main"><strong>{{ $incident->title }}</strong><span class="ss-row-sub">{{ $incident->subjectName() }} · {{ Time::relative($incident->opened_at) }} · {{ Lang::has('dashboard.overview.incident_status.'.$incident->status) ? __('dashboard.overview.incident_status.'.$incident->status) : $incident->status }}</span></span>
        </a>
    @empty
        <x-empty-state icon="i-check" :title="__('dashboard.overview.no_incidents')">{{ __('dashboard.overview.no_incidents_text') }}</x-empty-state>
    @endforelse
</section>
