@props(['id', 'label'])
{{-- A command to copy, in the same dark block as the install command. --}}
<div {{ $attributes->merge(['class' => 'ss-terminal']) }}>
    <div class="flex items-center justify-between gap-2 border-b border-[#1f1f23] px-3 py-1.5">
        <span class="text-xs text-[#a1a1aa]">{{ $label }}</span>
        <button class="ss-copy" type="button" data-copy-from="{{ $id }}">{{ __('common.copy') }}</button>
    </div>
    <pre id="{{ $id }}" aria-label="{{ $label }}">{{ $slot }}</pre>
</div>
