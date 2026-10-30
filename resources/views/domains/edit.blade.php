<x-layouts.app :title="__('domains.edit.title', ['name' => $domain->displayName()])">
    <x-slot:breadcrumb>
        <span>›</span> <a href="{{ route('domains.index') }}">{{ __('domains.title') }}</a>
        <span>›</span> <a href="{{ route('domains.show', $domain) }}">{{ $domain->displayName() }}</a> <span>›</span> {{ __('domains.edit_button') }}
    </x-slot:breadcrumb>
    <x-slot:pageTitle>{{ __('domains.edit.page_title') }}</x-slot:pageTitle>
    <x-slot:subtitle>{{ $domain->url() }}</x-slot:subtitle>
    <x-slot:actions><a href="{{ route('domains.show', $domain) }}" class="ss-btn ss-btn-ghost"><svg class="ico"><use href="#i-arrow-left"/></svg> {{ __('domains.edit.back_to_domain') }}</a></x-slot:actions>

    <div class="grid max-w-4xl gap-4">
        <form method="POST" action="{{ route('domains.update', $domain) }}" class="grid gap-4">
            @csrf
            @method('PUT')
            @include('domains._form')
            <div class="flex flex-wrap gap-2">
                <button type="submit" class="ss-btn ss-btn-primary">{{ __('domains.edit.submit') }}</button>
                <a href="{{ route('domains.show', $domain) }}" class="ss-btn ss-btn-ghost">{{ __('domains.cancel') }}</a>
            </div>
        </form>

        <section class="ss-panel ss-panel-danger mt-4" aria-labelledby="delete-title">
            <h2 id="delete-title" class="ss-panel-title">{{ __('domains.edit.delete_title') }}</h2>
            <p class="ss-panel-sub mb-4">{{ __('domains.edit.delete_text') }}</p>
            <form method="POST" action="{{ route('domains.destroy', $domain) }}" data-confirm="{{ __('domains.edit.delete_confirm', ['name' => $domain->displayName()]) }}">
                @csrf
                @method('DELETE')
                <button type="submit" class="ss-btn ss-btn-danger"><svg class="ico"><use href="#i-trash"/></svg> {{ __('domains.edit.delete') }}</button>
            </form>
        </section>
    </div>
</x-layouts.app>
