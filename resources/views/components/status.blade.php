@props(['status', 'label' => null])

@php
    // One colour vocabulary for every resource type.
    $color = match ($status) {
        'online', 'healthy', 'up', 'resolved', 'valid', 'operational', 'sent', 'enabled', 'active_ok' => 'ss-status-ok',
        'offline', 'down', 'critical', 'open', 'invalid', 'expired', 'major_outage', 'failed', 'error' => 'ss-status-bad',
        'warning', 'acknowledged', 'degraded', 'expiring', 'partial_outage' => 'ss-status-warn',
        'investigating', 'queued', 'info', 'maintenance', 'paused', 'scheduled' => 'ss-status-info',
        default => 'ss-status-muted',
    };

    $label ??= \App\Support\StatusLabel::for($status);
@endphp

<span {{ $attributes->class(['ss-status', $color]) }}>{{ $label }}</span>
