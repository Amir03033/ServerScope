@props(['severity'])

@php
    $color = match ($severity) {
        'critical' => 's-red',
        'high' => 's-orange',
        'warning' => 's-yellow',
        default => 's-cyan',
    };
@endphp

<span {{ $attributes->class(['pill-status', $color]) }}>{{ Lang::has('labels.severity.'.$severity) ? __('labels.severity.'.$severity) : \App\Support\Severity::label($severity) }}</span>
