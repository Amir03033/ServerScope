@props(['icon' => 'i-list', 'title'])

<div {{ $attributes->class('empty-state') }}>
    <div class="empty-icon"><svg class="ico"><use href="#{{ $icon }}"/></svg></div>
    <h3>{{ $title }}</h3>
    @if (trim($slot) !== '')
        <p>{{ $slot }}</p>
    @endif
    @isset($actions)
        <div class="actions">{{ $actions }}</div>
    @endisset
</div>
