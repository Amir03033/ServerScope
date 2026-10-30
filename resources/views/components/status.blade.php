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

    $label ??= \App\Support\StatusLabel::for($status);
@endphp

<span {{ $attributes->class(['pill-status', $color]) }}>{{ $label }}</span>
