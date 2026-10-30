@props(['severity'])

@php
    $color = match ($severity) {
        'critical' => 'ss-status-bad',
        'high' => 'ss-status-warn',
        'warning' => 'ss-status-warn',
        default => 'ss-status-info',
    };
@endphp

<span {{ $attributes->class(['ss-status', $color]) }}>{{ Lang::has('labels.severity.'.$severity) ? __('labels.severity.'.$severity) : \App\Support\Severity::label($severity) }}</span>
