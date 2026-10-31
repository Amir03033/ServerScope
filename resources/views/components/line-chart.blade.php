@props(['points', 'unit' => '', 'height' => 160, 'color' => 'var(--ss-accent)', 'label' => 'Grafiek', 'flagLabel' => null, 'markers' => []])

@php
    // $points: list of ['label' => string, 'value' => ?float, 'flag' => bool]
    $values = array_values(array_filter(array_column($points, 'value'), fn ($v) => $v !== null));
    $count = count($points);
    $max = $values ? max($values) : 0;
    $max = $max > 0 ? $max * 1.15 : 1;
    $w = 600;
    $h = (int) $height;
    $pad = ['l' => 44, 'r' => 8, 't' => 10, 'b' => 22];
    $plotW = $w - $pad['l'] - $pad['r'];
    $plotH = $h - $pad['t'] - $pad['b'];
    $x = fn ($i) => $pad['l'] + ($count > 1 ? $i / ($count - 1) : 0.5) * $plotW;
    $y = fn ($v) => $pad['t'] + $plotH - ($v / $max) * $plotH;

    // Break the line at gaps (no data) instead of interpolating.
    $segments = [];
    $current = [];
    foreach ($points as $i => $p) {
        if ($p['value'] === null) {
            if ($current) { $segments[] = $current; $current = []; }
            continue;
        }
        $current[] = round($x($i), 1).','.round($y($p['value']), 1);
    }
    if ($current) { $segments[] = $current; }

    $ticks = [0, $max / 2, $max];
@endphp

@if ($values === [])
    <div class="ss-empty !py-7"><p class="ss-empty-text !m-0">{{ __('components.line_chart.no_data') }}</p></div>
@else
    <svg viewBox="0 0 {{ $w }} {{ $h }}" role="img" aria-label="{{ $label }}" class="ss-linechart">
        @foreach ($ticks as $tick)
            <line x1="{{ $pad['l'] }}" x2="{{ $w - $pad['r'] }}" y1="{{ round($y($tick), 1) }}" y2="{{ round($y($tick), 1) }}" stroke="var(--ss-line)"/>
            <text x="{{ $pad['l'] - 6 }}" y="{{ round($y($tick), 1) + 3 }}" text-anchor="end" font-size="10" fill="var(--ss-muted)">{{ \App\Support\Format::number($tick) }}{{ $unit }}</text>
        @endforeach
        @foreach ($points as $i => $p)
            @if (! empty($p['flag']))
                <rect x="{{ round($x($i) - 2, 1) }}" y="{{ $pad['t'] }}" width="4" height="{{ $plotH }}" fill="color-mix(in srgb, var(--ss-bad) 28%, transparent)"><title>{{ $p['label'] }}: {{ $flagLabel ?? __('components.line_chart.failed_checks') }}</title></rect>
            @endif
        @endforeach
        {{-- Event markers (e.g. deployments): [['index' => int, 'label' => string]]. --}}
        @foreach ($markers as $marker)
            @if ($marker['index'] >= 0 && $marker['index'] < $count)
                <line x1="{{ round($x($marker['index']), 1) }}" x2="{{ round($x($marker['index']), 1) }}" y1="{{ $pad['t'] }}" y2="{{ $pad['t'] + $plotH }}" stroke="var(--ss-warn)" stroke-width="1.5" stroke-dasharray="4 3" data-marker><title>{{ $marker['label'] }}</title></line>
            @endif
        @endforeach
        @foreach ($segments as $segment)
            @if (count($segment) === 1)
                @php [$cx, $cy] = explode(',', $segment[0]); @endphp
                <circle cx="{{ $cx }}" cy="{{ $cy }}" r="2.5" fill="{{ $color }}"/>
            @else
                <polyline points="{{ implode(' ', $segment) }}" fill="none" stroke="{{ $color }}" stroke-width="2" stroke-linejoin="round" vector-effect="non-scaling-stroke"/>
            @endif
        @endforeach
        @foreach ([0, intdiv($count - 1, 2), $count - 1] as $i)
            @isset($points[$i])
                <text x="{{ round($x($i), 1) }}" y="{{ $h - 6 }}" text-anchor="{{ $i === 0 ? 'start' : ($i === $count - 1 ? 'end' : 'middle') }}" font-size="10" fill="var(--ss-muted)">{{ $points[$i]['label'] }}</text>
            @endisset
        @endforeach
    </svg>
@endif
