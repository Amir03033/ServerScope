@props(['label', 'links' => []])

{{-- Three-dot row menu: links ([label, href, icon]) and optional forms in the slot. --}}
<details class="ss-menu ss-row-actions" data-menu>
    <summary class="ss-btn ss-btn-ghost ss-btn-sm" aria-label="{{ $label }}" title="{{ $label }}">
        <svg class="ico" aria-hidden="true" viewBox="0 0 24 24"><circle cx="5" cy="12" r="1.6" fill="currentColor"/><circle cx="12" cy="12" r="1.6" fill="currentColor"/><circle cx="19" cy="12" r="1.6" fill="currentColor"/></svg>
    </summary>
    <div class="ss-menu-panel" role="menu">
        @foreach ($links as [$text, $href, $icon])
            <a class="ss-menu-item" role="menuitem" href="{{ $href }}"><svg class="ico" aria-hidden="true"><use href="#{{ $icon }}"/></svg>{{ $text }}</a>
        @endforeach
        {{ $slot }}
    </div>
</details>
