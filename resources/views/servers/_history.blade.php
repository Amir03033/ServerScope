@php
    $hasData = fn (array $points) => collect($points)->contains(fn ($p) => $p['value'] !== null);
    $charts = [
        ['swap', 'Swapgebruik', '%', 'var(--ss-subtle)', 'Aandeel gebruikt swapgeheugen.'],
        ['rx', 'Netwerk inkomend', ' KiB/s', null, 'Gemiddeld tussen opeenvolgende metingen.'],
        ['tx', 'Netwerk uitgaand', ' KiB/s', 'var(--ss-subtle)', 'Gemiddeld tussen opeenvolgende metingen.'],
        ['disk_read', 'Schijf lezen', ' KiB/s', null, 'Alle schijven samen (agent 0.2.0+).'],
        ['disk_write', 'Schijf schrijven', ' KiB/s', null, 'Alle schijven samen (agent 0.2.0+).'],
        ['errors', 'Netwerkfouten', '/min', 'var(--ss-bad)', 'Ontvangst- en verzendfouten van alle interfaces (agent 0.2.0+).'],
    ];
@endphp

<div class="section-heading">
    <div><h2>Uitgebreide historie</h2><p>Afgeleid uit de metingen in de gekozen periode. Een teller die terugloopt (herstart) geeft een onderbreking, geen piek.</p></div>
</div>
<div class="grid-3" style="margin-bottom:14px">
    @foreach ($charts as [$key, $title, $unit, $color, $help])
        <section class="panel">
            <h3 style="margin:0 0 2px;font-size:14px">{{ $title }}</h3>
            <p class="muted small" style="margin:0 0 8px">{{ $help }}</p>
            @if ($hasData($history[$key]))
                <x-line-chart :points="$history[$key]" :unit="$unit" :height="130" :color="$color ?? 'var(--ss-accent)'" :label="$title" />
            @else
                <p class="muted small">Geen gegevens in deze periode.</p>
            @endif
        </section>
    @endforeach
</div>
