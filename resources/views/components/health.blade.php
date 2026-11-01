@props(['state'])

@php
    // App\Support\Health states: text + icon + description, never colour alone.
    [$class, $icon] = match ($state) {
        'healthy' => ['ss-status-ok', 'i-check'],
        'warning' => ['ss-status-warn', 'i-alert'],
        'critical', 'offline' => ['ss-status-bad', $state === 'offline' ? 'i-down' : 'i-x'],
        'maintenance' => ['ss-status-info', 'i-wrench'],
        'paused' => ['ss-status-info', 'i-pause'],
        default => ['ss-status-muted', 'i-status'],
    };
    $label = __('health.states.'.$state);
    $description = __('health.descriptions.'.$state);
@endphp

<span {{ $attributes->class(['ss-status', $class]) }} title="{{ $description }}" data-health="{{ $state }}">
    <svg class="ico" aria-hidden="true"><use href="#{{ $icon }}"/></svg>{{ $label }}<span class="sr-only">: {{ $description }}</span>
</span>
