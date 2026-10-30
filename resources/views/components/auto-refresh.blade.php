@props(['seconds' => 30])

{{--
    Pausable automatic refresh. Shown and run by app.js; off while
    prefers-reduced-motion is set (the visitor can still turn it on).
    Without JavaScript the page adds <noscript><meta http-equiv="refresh"></noscript>.
--}}
<div {{ $attributes->class('ss-auto-refresh') }} data-auto-refresh="{{ (int) $seconds }}" hidden
     data-text-active="{{ __('common.auto_refresh.active', ['seconds' => (int) $seconds]) }}"
     data-text-paused="{{ __('common.auto_refresh.paused') }}"
     data-text-reduced="{{ __('common.auto_refresh.reduced') }}"
     data-label-pause="{{ __('common.auto_refresh.pause') }}"
     data-label-resume="{{ __('common.auto_refresh.resume') }}">
    <span class="ss-dot ss-dot-accent" data-auto-refresh-dot aria-hidden="true"></span>
    <span data-auto-refresh-text></span>
    <button type="button" class="ss-btn ss-btn-ghost ss-btn-sm" data-auto-refresh-toggle aria-pressed="false">{{ __('common.auto_refresh.pause') }}</button>
    <a class="ss-btn ss-btn-ghost ss-btn-sm" href="{{ request()->fullUrl() }}">{{ __('common.auto_refresh.refresh') }}</a>
</div>
