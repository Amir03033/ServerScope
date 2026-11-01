<x-layouts.app :title="__('domains.create.title')">
    <x-slot:breadcrumb><span>›</span> <a href="{{ route('domains.index') }}">{{ __('domains.title') }}</a> <span>›</span> {{ __('domains.create.crumb') }}</x-slot:breadcrumb>
    <x-slot:subtitle>{{ __('domains.create.subtitle') }}</x-slot:subtitle>
    <x-slot:actions><a href="{{ route('domains.index') }}" class="ss-btn ss-btn-ghost"><svg class="ico"><use href="#i-arrow-left"/></svg> {{ __('domains.back') }}</a></x-slot:actions>

    @isset($duplicateOf)
        <div class="ss-alert ss-alert-info mb-4 max-w-4xl" role="status"><svg class="ico"><use href="#i-copy"/></svg><div>{{ __('domains.duplicate_notice', ['name' => $duplicateOf->displayName()]) }}</div></div>
    @endisset
    <form method="POST" action="{{ route('domains.store') }}" class="grid max-w-4xl gap-4">
        @csrf
        @include('domains._form')
        <div class="flex flex-wrap gap-2">
            <button type="submit" class="ss-btn ss-btn-primary">{{ __('domains.create.submit') }}</button>
            <a href="{{ route('domains.index') }}" class="ss-btn ss-btn-ghost">{{ __('domains.cancel') }}</a>
        </div>
    </form>
</x-layouts.app>
