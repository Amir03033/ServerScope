@props(['points', 'unit' => '', 'height' => 160, 'color' => '#38bdf8', 'label' => 'Grafiek'])

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
    <div class="empty-state" style="padding:28px"><p style="margin:0">Nog geen meetgegevens in deze periode.</p></div>
@else
    <svg viewBox="0 0 {{ $w }} {{ $h }}" role="img" aria-label="{{ $label }}" style="width:100%;height:auto;display:block">
        @foreach ($ticks as $tick)
            <line x1="{{ $pad['l'] }}" x2="{{ $w - $pad['r'] }}" y1="{{ round($y($tick), 1) }}" y2="{{ round($y($tick), 1) }}" stroke="rgba(148,163,184,.12)"/>
            <text x="{{ $pad['l'] - 6 }}" y="{{ round($y($tick), 1) + 3 }}" text-anchor="end" font-size="10" fill="#8397b1">{{ number_format($tick, 0, ',', '.') }}{{ $unit }}</text>
        @endforeach
        @foreach ($points as $i => $p)
            @if (! empty($p['flag']))
                <rect x="{{ round($x($i) - 2, 1) }}" y="{{ $pad['t'] }}" width="4" height="{{ $plotH }}" fill="rgba(251,113,133,.35)"><title>{{ $p['label'] }}: mislukte controles</title></rect>
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
                <text x="{{ round($x($i), 1) }}" y="{{ $h - 6 }}" text-anchor="{{ $i === 0 ? 'start' : ($i === $count - 1 ? 'end' : 'middle') }}" font-size="10" fill="#8397b1">{{ $points[$i]['label'] }}</text>
            @endisset
        @endforeach
    </svg>
@endif
