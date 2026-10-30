<x-layouts.app :title="__('servers.add_button')">
    <x-slot:breadcrumb>
        <span>›</span> <a href="{{ route('servers.index') }}">{{ __('servers.title') }}</a> <span>›</span> {{ __('servers.add_short') }}
    </x-slot:breadcrumb>
    <x-slot:subtitle>{{ __('servers.create.subtitle') }}@if (config('serverscope.docs_url')) <a class="text-accent" href="{{ config('serverscope.docs_url') }}/server-monitoring" target="_blank" rel="noopener">{{ __('servers.create.how') }}</a>@endif</x-slot:subtitle>

    <x-slot:actions>
        <a href="{{ route('servers.index') }}" class="ss-btn ss-btn-ghost"><svg class="ico"><use href="#i-arrow-left"/></svg> {{ __('servers.back') }}</a>
    </x-slot:actions>

    <section class="ss-panel max-w-4xl">
        <form method="POST" action="{{ route('servers.store') }}">
            @csrf
            @include('servers._form')

            <div class="mt-6 flex flex-wrap gap-2 border-t border-line pt-5">
                <button type="submit" class="ss-btn ss-btn-primary">{{ __('servers.create.submit') }}</button>
                <a href="{{ route('servers.index') }}" class="ss-btn ss-btn-ghost">{{ __('servers.cancel') }}</a>
            </div>
        </form>
    </section>
</x-layouts.app>
