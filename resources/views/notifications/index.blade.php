@php use App\Support\Time; @endphp

<x-layouts.app title="Meldingen">
    <x-slot:subtitle>Je meldingen en de verzendgeschiedenis.</x-slot:subtitle>
    <x-slot:actions>
        @if ($tab === 'inbox')
            <form method="POST" action="{{ route('notifications.read-all') }}" class="inline-form">@csrf<button class="button" type="submit">Alles als gelezen markeren</button></form>
        @endif
        <a class="button" href="{{ route('profile.edit') }}">Voorkeuren</a>
    </x-slot:actions>

    <section class="panel">
        <nav class="filters">
            <a href="{{ route('notifications.index') }}" @class(['filter', 'active' => $tab === 'inbox'])>Inbox @if ($sidebarUnreadNotifications ?? 0)<span class="filter-count">{{ $sidebarUnreadNotifications }}</span>@endif</a>
            <a href="{{ route('notifications.index', ['tab' => 'deliveries']) }}" @class(['filter', 'active' => $tab === 'deliveries'])>Verzendgeschiedenis{{ auth()->user()->can('admin') ? ' (alle gebruikers)' : '' }}</a>
        </nav>

        @if ($tab === 'inbox')
            @if ($notifications->isEmpty())
                <x-empty-state icon="i-message" title="Geen meldingen">
                    {{ auth()->user()->notify_in_app ? 'Nieuwe meldingen verschijnen hier.' : 'In-app meldingen staan uit. Zet ze aan in je profiel.' }}
                </x-empty-state>
            @else
                @foreach ($notifications as $notification)
                    <form method="POST" action="{{ route('notifications.read', $notification->id) }}" style="margin:0">
                        @csrf
                        <button type="submit" class="ov-row" style="width:calc(100% + 16px);border:0;background:none;text-align:left;cursor:pointer">
                            <x-status :status="$notification->data['severity'] ?? 'info'" />
                            <span class="ov-main">
                                <strong @class(['muted' => $notification->read_at])>{{ $notification->data['message'] ?? $notification->data['title'] ?? 'Melding' }}</strong>
                                <span class="cell-sub">{{ Time::format($notification->created_at, 'd-m-Y H:i') }}@if ($notification->read_at) · gelezen @else · <span class="cyan">nieuw</span>@endif</span>
                            </span>
                        </button>
                    </form>
                @endforeach
                <x-pagination :paginator="$notifications" />
            @endif
        @else
            @if ($deliveries->isEmpty())
                <x-empty-state icon="i-history" title="Nog niets verzonden">Hier verschijnt elke verzonden, mislukte of onderdrukte melding.</x-empty-state>
            @else
                <div class="table-wrap">
                    <table class="data-table">
                        <thead><tr><th>Tijdstip</th><th>Ontvanger</th><th>Kanaal</th><th>Onderwerp</th><th>Status</th></tr></thead>
                        <tbody>
                            @foreach ($deliveries as $log)
                                <tr>
                                    <td class="nowrap">{{ Time::format($log->created_at, 'd-m-Y H:i:s') }}</td>
                                    <td class="small">{{ $log->user?->name ?? $log->recipient ?? '—' }}</td>
                                    <td><span class="chip">{{ ['mail' => 'E-mail', 'database' => 'In-app', '-' => '—'][$log->channel] ?? $log->channel }}</span></td>
                                    <td class="small break">
                                        {{ $log->subject ?? $log->kind }}
                                        @if ($log->incident)<span class="cell-sub"><a href="{{ route('incidents.show', $log->incident_id) }}">Incident #{{ $log->incident_id }}</a></span>@endif
                                    </td>
                                    <td>
                                        <x-status :status="$log->status === 'suppressed' ? 'paused' : $log->status" :label="['queued' => 'In wachtrij', 'sent' => 'Verzonden', 'failed' => 'Mislukt', 'suppressed' => 'Onderdrukt'][$log->status] ?? $log->status" />
                                        @if ($log->error)<span class="cell-sub">{{ $log->error }}</span>@endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <x-pagination :paginator="$deliveries" />
            @endif
        @endif
    </section>
</x-layouts.app>
