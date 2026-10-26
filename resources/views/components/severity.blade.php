@props(['severity'])

@php
    $color = match ($severity) {
        'critical' => 's-red',
        'high' => 's-orange',
        'warning' => 's-yellow',
        default => 's-cyan',
    };
@endphp

<span {{ $attributes->class(['pill-status', $color]) }}>{{ \App\Support\Severity::label($severity) }}</span>
