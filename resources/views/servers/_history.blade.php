@php
    $hasData = fn (array $points) => collect($points)->contains(fn ($p) => $p['value'] !== null);
    $charts = [
        ['swap', '%', 'var(--ss-subtle)'],
        ['rx', ' KiB/s', null],
        ['tx', ' KiB/s', 'var(--ss-subtle)'],
        ['disk_read', ' KiB/s', null],
        ['disk_write', ' KiB/s', null],
        ['errors', '/min', 'var(--ss-bad)'],
    ];
@endphp

<div>
    <h3 class="m-0 text-base font-semibold">{{ __('servers.history.title') }}</h3>
    <p class="ss-panel-sub mb-3">{{ __('servers.history.text') }}</p>
    <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
        @foreach ($charts as [$key, $unit, $color])
            @php [$title, $help] = __('servers.history.charts.'.$key); @endphp
            <section class="ss-panel !p-4">
                <h4 class="m-0 text-sm font-semibold">{{ $title }}</h4>
                <p class="m-0 mb-2 text-xs text-muted">{{ $help }}</p>
                @if ($hasData($history[$key]))
                    <x-line-chart :points="$history[$key]" :unit="$unit" :height="130" :color="$color ?? 'var(--ss-accent)'" :label="$title" />
                @else
                    <p class="m-0 text-xs text-muted">{{ __('servers.history.no_data') }}</p>
                @endif
            </section>
        @endforeach
    </div>
</div>
