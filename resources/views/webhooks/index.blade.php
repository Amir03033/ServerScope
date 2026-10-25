@php use App\Support\Time; @endphp

<x-layouts.app title="Webhooks">
    <x-slot:subtitle>Stuur incident- en DNS-gebeurtenissen naar andere systemen (Slack/Discord via een relay, n8n, je eigen API). Elke levering is ondertekend.</x-slot:subtitle>
    <x-slot:actions><a class="button button-primary" href="{{ route('webhooks.create') }}"><svg class="ico"><use href="#i-plus"/></svg> Webhook toevoegen</a></x-slot:actions>

    <section class="panel">
        @if ($endpoints->isEmpty())
            <x-empty-state icon="i-webhook" title="Nog geen webhooks">Voeg een endpoint toe om gebeurtenissen automatisch door te sturen.</x-empty-state>
        @else
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th>Naam</th><th>Gebeurtenissen</th><th>Status</th><th>Laatste levering</th><th class="num">Leveringen</th></tr></thead>
                    <tbody>
                        @foreach ($endpoints as $endpoint)
                            <tr>
                                <td><a class="row-link" href="{{ route('webhooks.show', $endpoint) }}">{{ $endpoint->name }}</a><span class="cell-sub">{{ parse_url($endpoint->url, PHP_URL_HOST) }}</span></td>
                                <td class="small">{{ count($endpoint->events) }} geselecteerd</td>
                                <td>
                                    <x-status :status="$endpoint->is_active ? 'enabled' : 'disabled'" />
                                    @if ($endpoint->consecutive_failures)<span class="cell-sub red">{{ $endpoint->consecutive_failures }} mislukt op rij</span>@endif
                                </td>
                                <td class="small">{{ Time::relative($endpoint->last_delivery_at) }} @if ($endpoint->last_status)· {{ $endpoint->last_status === 'success' ? 'gelukt' : 'mislukt' }}@endif</td>
                                <td class="num">{{ $endpoint->deliveries_count }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</x-layouts.app>
