@props(['icon' => 'i-list', 'title'])

<div {{ $attributes->class('ss-empty') }}>
    <div class="ss-empty-icon"><svg class="ico"><use href="#{{ $icon }}"/></svg></div>
    <h3 class="ss-empty-title">{{ $title }}</h3>
    @if (trim($slot) !== '')
        <p class="ss-empty-text">{{ $slot }}</p>
    @endif
    @isset($actions)
        <div class="actions">{{ $actions }}</div>
    @endisset
</div>
