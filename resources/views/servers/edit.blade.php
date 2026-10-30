<x-layouts.app :title="__('servers.edit.title', ['name' => $server->name])">
    <x-slot:breadcrumb>
        <span>›</span> <a href="{{ route('servers.index') }}">{{ __('servers.title') }}</a>
        <span>›</span> <a href="{{ route('servers.show', $server) }}">{{ $server->name }}</a>
        <span>›</span> {{ __('servers.edit_button') }}
    </x-slot:breadcrumb>
    <x-slot:pageTitle>{{ __('servers.edit.page_title') }}</x-slot:pageTitle>
    <x-slot:subtitle>{{ $server->name }} · {{ $server->hostname }}</x-slot:subtitle>

    <x-slot:actions>
        <a href="{{ route('servers.show', $server) }}" class="ss-btn ss-btn-ghost"><svg class="ico"><use href="#i-arrow-left"/></svg> {{ __('servers.back_to_server') }}</a>
    </x-slot:actions>

    <div class="grid max-w-4xl gap-4">
        <section class="ss-panel">
            <form method="POST" action="{{ route('servers.update', $server) }}">
                @csrf
                @method('PUT')
                @include('servers._form')

                <div class="mt-6 flex flex-wrap gap-2 border-t border-line pt-5">
                    <button type="submit" class="ss-btn ss-btn-primary">{{ __('servers.edit.submit') }}</button>
                    <a href="{{ route('servers.show', $server) }}" class="ss-btn ss-btn-ghost">{{ __('servers.cancel') }}</a>
                </div>
            </form>
        </section>

        <section class="ss-panel ss-panel-danger" aria-labelledby="delete-title">
            <h2 id="delete-title" class="ss-panel-title">{{ __('servers.edit.delete_title') }}</h2>
            <p class="ss-panel-sub mb-4">{{ __('servers.edit.delete_text') }}</p>
            <form method="POST" action="{{ route('servers.destroy', $server) }}"
                  data-confirm="{{ __('servers.edit.delete_confirm', ['name' => $server->name]) }}">
                @csrf
                @method('DELETE')
                <button type="submit" class="ss-btn ss-btn-danger"><svg class="ico"><use href="#i-trash"/></svg> {{ __('servers.edit.delete') }}</button>
            </form>
        </section>
    </div>
</x-layouts.app>
