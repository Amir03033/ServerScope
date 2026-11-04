{{-- Opened from "Acknowledge" in a chat message: confirm with one tap. --}}
@php
    use App\Services\Dependencies\ResourceCatalog;
    use App\Support\Time;
@endphp

<x-layouts.app :title="__('incidents.acknowledge.title', ['id' => $incident->id])">
    <x-slot:breadcrumb><span>›</span> <a href="{{ route('incidents.index') }}">{{ __('incidents.title') }}</a> <span>›</span> <a href="{{ route('incidents.show', $incident) }}">#{{ $incident->id }}</a></x-slot:breadcrumb>

    <section class="ss-panel ss-ack" aria-labelledby="ack-title">
        <span class="ss-ack-icon" aria-hidden="true"><svg class="ico"><use href="#i-siren"/></svg></span>
        <p class="m-0 text-sm text-muted">{{ __('incidents.acknowledge.kicker') }}</p>
        <h2 id="ack-title" class="ss-ack-title">{{ $incident->title }}</h2>
        <p class="ss-ack-meta">
            <x-severity :severity="$incident->severity" />
            <x-status :status="$incident->status" />
            @if ($incident->subject)<span>{{ ResourceCatalog::name($incident->subject) }}</span>@endif
            <span>{{ __('incidents.acknowledge.opened', ['time' => Time::relative($incident->opened_at)]) }}</span>
        </p>
        <p class="m-0 max-w-md text-sm text-muted">{{ __('incidents.acknowledge.text') }}</p>
        <div class="ss-ack-actions">
            <form method="POST" action="{{ route('incidents.transition', $incident) }}">
                @csrf
                <input type="hidden" name="status" value="acknowledged">
                <button class="ss-btn ss-btn-primary" type="submit"><svg class="ico"><use href="#i-check"/></svg> {{ __('incidents.show.actions.acknowledged') }}</button>
            </form>
            <a class="ss-btn ss-btn-secondary" href="{{ route('incidents.show', $incident) }}">{{ __('incidents.acknowledge.view') }}</a>
        </div>
    </section>
</x-layouts.app>
