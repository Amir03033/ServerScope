@props(['token', 'title' => null, 'id' => 'secret-token'])

@php $title ??= __('components.secret_token.title'); @endphp

<div class="secret-box" role="status">
    <strong>{{ $title }}</strong>
    <p class="small" style="margin:6px 0 0">
        {{ __('components.secret_token.once') }}
    </p>
    {{ $slot }}
    <div class="secret-row">
        <input id="{{ $id }}" class="input mono" type="password" readonly value="{{ $token }}" autocomplete="off" aria-label="{{ $title }}">
        <button class="button" type="button" data-secret-toggle="{{ $id }}">{{ __('common.show') }}</button>
        <button class="button" type="button" data-copy-from="{{ $id }}">{{ __('common.copy') }}</button>
    </div>
</div>
