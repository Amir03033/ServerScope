<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('billing.invoices.invoice') }} {{ $invoice->number }}</title>
    <style>
        body { font: 14px/1.5 system-ui, sans-serif; color: #111; max-width: 760px; margin: 2rem auto; padding: 0 1rem; }
        table { width: 100%; border-collapse: collapse; margin-top: 1.5rem; }
        th, td { text-align: left; padding: .5rem; border-bottom: 1px solid #ddd; }
        .r { text-align: right; } .cols { display: flex; justify-content: space-between; gap: 2rem; flex-wrap: wrap; }
        .muted { color: #555; } @media print { .noprint { display: none; } }
    </style>
</head>
<body>
    <p class="noprint"><a href="{{ route('billing.invoices') }}">← {{ __('billing.invoices.title') }}</a> · <a href="#" onclick="window.print();return false">{{ __('billing.invoices.print') }}</a></p>
    @php($s = $invoice->seller ?? [])
    @php($b = $invoice->buyer ?? [])
    <h1>{{ __('billing.invoices.invoice') }} {{ $invoice->number }}</h1>
    <div class="cols">
        <div><strong>{{ $s['name'] ?? '' }}</strong><br>{!! nl2br(e($s['address'] ?? '')) !!}<br>{{ __('billing.invoices.kvk') }}: {{ $s['kvk'] ?? '' }}
            @if (!empty($s['vat_id']))<br>{{ __('billing.invoices.vat_id') }}: {{ $s['vat_id'] }}@endif<br>{{ $s['email'] ?? '' }}</div>
        <div><strong>{{ $b['name'] ?? '' }}</strong><br>{!! nl2br(e($b['address'] ?? '')) !!}
            @if (!empty($b['vat_id']))<br>{{ __('billing.invoices.vat_id') }}: {{ $b['vat_id'] }}@endif</div>
    </div>
    <p class="muted">{{ __('billing.invoices.date') }}: {{ $invoice->issued_at->format('Y-m-d') }} · {{ __('billing.invoices.paid') }}: {{ $invoice->paid_at->format('Y-m-d') }}</p>
    <table>
        <tr><th>{{ __('billing.invoices.description') }}</th><th class="r">{{ __('billing.invoices.amount') }}</th></tr>
        <tr><td>ServerScope {{ __('plans.names.'.$invoice->plan) }} ({{ __('billing.interval.'.$invoice->interval) }})
            @if ($invoice->period_end)<br><span class="muted">{{ $invoice->period_start->format('Y-m-d') }} – {{ $invoice->period_end->format('Y-m-d') }}</span>@endif</td>
            <td class="r">{{ \App\Models\Invoice::money($invoice->amount_excl, $invoice->currency) }}</td></tr>
        <tr><td>{{ __('billing.invoices.vat') }} {{ rtrim(rtrim(number_format($invoice->vat_rate, 2, ',', ''), '0'), ',') }}%</td><td class="r">{{ \App\Models\Invoice::money($invoice->vat_amount, $invoice->currency) }}</td></tr>
        <tr><th>{{ __('billing.invoices.total') }}</th><th class="r">{{ \App\Models\Invoice::money($invoice->amount_incl, $invoice->currency) }}</th></tr>
    </table>
    @if ($invoice->vat_note)<p class="muted">{{ $invoice->vat_note }}</p>@endif
</body>
</html>
