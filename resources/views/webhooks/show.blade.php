@php use App\Support\Time; @endphp

<x-layouts.app :title="'Webhook: '.$endpoint->name">
    <x-slot:breadcrumb><span>›</span> <a href="{{ route('webhooks.index') }}">Webhooks</a> <span>›</span> {{ $endpoint->name }}</x-slot:breadcrumb>
    <x-slot:pageTitle>{{ $endpoint->name }}</x-slot:pageTitle>
    <x-slot:subtitle class="break">{{ $endpoint->url }}</x-slot:subtitle>
    <x-slot:actions>
        <form method="POST" action="{{ route('webhooks.test', $endpoint) }}" class="inline-form">@csrf<button class="button" type="submit">Testlevering</button></form>
        <a class="button" href="{{ route('webhooks.edit', $endpoint) }}"><svg class="ico"><use href="#i-edit"/></svg> Bewerken</a>
    </x-slot:actions>

    @if (session('webhook_secret'))
        <x-secret-token :token="session('webhook_secret')" title="Ondertekeningsgeheim" id="webhook-secret">
            <p class="small" style="margin:8px 0 0">Controleer elke levering: <code>X-ServerScope-Signature</code> = <code>sha256=</code>HMAC-SHA256(geheim, <code>X-ServerScope-Timestamp + "." + body</code>). Weiger leveringen ouder dan 5 minuten.</p>
        </x-secret-token>
    @endif

    <div class="grid-2" style="grid-template-columns:minmax(0,1fr) minmax(0,2fr)">
        <section class="panel">
            <div class="section-heading"><div><h2>Instellingen</h2></div><x-status :status="$endpoint->is_active ? 'enabled' : 'disabled'" /></div>
            <dl class="dl">
                <dt>Gebeurtenissen</dt><dd>@foreach ($endpoint->events as $e)<code class="chip">{{ $e }}</code> @endforeach</dd>
                <dt>Mislukt op rij</dt><dd>{{ $endpoint->consecutive_failures }} (uitgeschakeld na {{ \App\Jobs\DeliverWebhook::DISABLE_AFTER_FAILURES }})</dd>
                <dt>Laatste levering</dt><dd>{{ $endpoint->last_delivery_at ? Time::format($endpoint->last_delivery_at) : '—' }}</dd>
            </dl>
            <div class="form-footer">
                <form method="POST" action="{{ route('webhooks.secret', $endpoint) }}" class="inline-form" data-confirm="Nieuw geheim genereren? Het huidige werkt daarna niet meer.">@csrf<button class="button button-warning" type="submit">Geheim vernieuwen</button></form>
                <form method="POST" action="{{ route('webhooks.destroy', $endpoint) }}" class="inline-form" data-confirm="Webhook en leveringsgeschiedenis verwijderen?">@csrf @method('DELETE')<button class="button button-danger" type="submit">Verwijderen</button></form>
            </div>
        </section>

        <section class="panel">
            <div class="section-heading"><div><h2>Leveringen</h2><p>Mislukte leveringen worden tot 6 keer opnieuw geprobeerd (1 min → 6 uur).</p></div></div>
            @if ($deliveries->isEmpty())
                <x-empty-state icon="i-history" title="Nog geen leveringen">Stuur een testlevering om de koppeling te controleren.</x-empty-state>
            @else
                <div class="table-wrap">
                    <table class="data-table">
                        <thead><tr><th>Tijdstip</th><th>Gebeurtenis</th><th>Status</th><th class="num">HTTP</th><th class="num">Pogingen</th><th></th></tr></thead>
                        <tbody>
                            @foreach ($deliveries as $delivery)
                                <tr>
                                    <td class="nowrap">{{ Time::format($delivery->created_at, 'd-m-Y H:i:s') }}<span class="cell-sub mono">{{ $delivery->uuid }}</span></td>
                                    <td><code>{{ $delivery->event }}</code></td>
                                    <td>
                                        <x-status :status="['success' => 'sent', 'failed' => 'failed', 'pending' => 'queued', 'retrying' => 'warning'][$delivery->status] ?? 'unknown'" :label="['success' => 'Geleverd', 'failed' => 'Mislukt', 'pending' => 'In wachtrij', 'retrying' => 'Opnieuw proberen'][$delivery->status] ?? $delivery->status" />
                                        @if ($delivery->error)<span class="cell-sub">{{ $delivery->error }}</span>@endif
                                    </td>
                                    <td class="num">{{ $delivery->response_status ?? '—' }}</td>
                                    <td class="num">{{ $delivery->attempts }}</td>
                                    <td>
                                        @if ($delivery->status === 'failed')
                                            <form method="POST" action="{{ route('webhooks.redeliver', [$endpoint, $delivery]) }}" class="row-actions">@csrf<button class="button button-sm" type="submit">Opnieuw</button></form>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <x-pagination :paginator="$deliveries" />
            @endif
        </section>
    </div>
</x-layouts.app>
