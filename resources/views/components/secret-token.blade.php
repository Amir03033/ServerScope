@props(['token', 'title' => null, 'id' => 'secret-token'])

@php $title ??= __('components.secret_token.title'); @endphp

<div class="ss-alert ss-alert-warning ss-secret" role="status">
    <svg class="ico"><use href="#i-key"/></svg>
    <div class="min-w-0 flex-1">
        <strong>{{ $title }}</strong>
        <p class="mt-1 mb-0 text-sm">{{ __('components.secret_token.once') }}</p>
        {{ $slot }}
        <div class="mt-3 flex flex-wrap gap-2">
            <input id="{{ $id }}" class="ss-input min-w-0 flex-1 font-mono" type="password" readonly value="{{ $token }}" autocomplete="off" aria-label="{{ $title }}">
            <button class="ss-btn ss-btn-secondary" type="button" data-secret-toggle="{{ $id }}">{{ __('common.show') }}</button>
            <button class="ss-btn ss-btn-secondary" type="button" data-copy-from="{{ $id }}">{{ __('common.copy') }}</button>
        </div>
    </div>
</div>
