@props(['status', 'label' => null])

@php
    // One colour vocabulary for every resource type.
    $color = match ($status) {
        'online', 'healthy', 'up', 'resolved', 'valid', 'operational', 'sent', 'enabled', 'active_ok' => 's-green',
        'offline', 'down', 'critical', 'open', 'invalid', 'expired', 'major_outage', 'failed', 'error' => 's-red',
        'warning', 'acknowledged', 'degraded', 'expiring', 'partial_outage' => 's-yellow',
        'investigating', 'queued', 'info' => 's-cyan',
        'maintenance', 'paused', 'scheduled' => 's-purple',
        default => 's-muted',
    };

    $label ??= [
        'online' => 'Online', 'offline' => 'Offline', 'pending' => 'In afwachting', 'maintenance' => 'Onderhoud',
        'healthy' => 'Gezond', 'up' => 'Bereikbaar', 'down' => 'Onbereikbaar', 'warning' => 'Waarschuwing',
        'critical' => 'Kritiek', 'unknown' => 'Onbekend', 'paused' => 'Gepauzeerd',
        'open' => 'Open', 'acknowledged' => 'Bevestigd', 'investigating' => 'In onderzoek',
        'resolved' => 'Opgelost', 'closed' => 'Gesloten', 'info' => 'Info',
        'valid' => 'Geldig', 'invalid' => 'Ongeldig', 'expired' => 'Verlopen', 'expiring' => 'Verloopt binnenkort',
        'operational' => 'Operationeel', 'degraded' => 'Verminderde prestaties',
        'partial_outage' => 'Gedeeltelijke storing', 'major_outage' => 'Grote storing',
        'sent' => 'Verzonden', 'failed' => 'Mislukt', 'queued' => 'In wachtrij', 'error' => 'Fout',
        'scheduled' => 'Gepland', 'enabled' => 'Ingeschakeld', 'disabled' => 'Uitgeschakeld',
    ][$status] ?? ucfirst(str_replace('_', ' ', (string) $status));
@endphp

<span {{ $attributes->class(['pill-status', $color]) }}>{{ $label }}</span>
