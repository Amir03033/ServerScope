@php use App\Support\Time; @endphp
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Beschikbaarheidsrapport {{ Time::format($report['from'], 'd-m-Y') }} – {{ Time::format($report['to'], 'd-m-Y') }} — ServerScope</title>
    <link rel="stylesheet" href="{{ asset('css/serverscope.css') }}?v={{ @filemtime(public_path('css/serverscope.css')) }}">
    <style>
        /* Light, printable variant of the shared styles. */
        :root { --text: #0f172a; --muted: #475569; }
        body { background: #fff; color: #0f172a; padding: 24px; }
        .panel, .kpi { background: #fff !important; border: 1px solid #cbd5e1 !important; box-shadow: none !important; color: #0f172a; }
        .data-table th, .data-table td, .dl dt, .dl dd { color: #0f172a !important; border-color: #e2e8f0 !important; }
        .kpi-value, .kpi-label, h1, h2, h3, a { color: #0f172a !important; }
        .muted, .cell-sub, .kpi-caption, .section-heading p { color: #475569 !important; }
        .no-print { margin-bottom: 16px; }
        @media print { .no-print { display: none; } body { padding: 0; } .panel { break-inside: avoid; } }
    </style>
</head>
<body>
@include('partials.icons')
<div class="no-print"><button class="button" type="button" onclick="window.print()">Afdrukken / opslaan als PDF</button></div>
<h1 style="margin:0 0 4px">Beschikbaarheidsrapport</h1>
<p class="muted" style="margin:0 0 16px">{{ Time::format($report['from']) }} t/m {{ Time::format($report['to']) }} ({{ Time::zone() }}) · gegenereerd {{ Time::format(now()) }} · standaarddoel {{ number_format($report['target'], 2, ',', '.') }}%.
    Berekende beschikbaarheid uit monitoringgegevens, geen contractuele SLA-vaststelling. Rekenmethode: zie de documentatie (docs/REPORTS.md).</p>
@include('reports._body')
</body>
</html>
