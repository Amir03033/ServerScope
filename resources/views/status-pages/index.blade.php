<x-layouts.app :title="__('status_pages.title')">
    <x-slot:subtitle>{{ __('status_pages.subtitle') }}</x-slot:subtitle>
    <x-slot:actions><a class="ss-btn ss-btn-primary" href="{{ route('status-pages.create') }}"><svg class="ico"><use href="#i-plus"/></svg> {{ __('status_pages.create_button') }}</a></x-slot:actions>

    <section class="ss-panel">
        @if ($pages->isEmpty())
            <x-empty-state icon="i-status" :title="__('status_pages.empty_title')">
                {{ __('status_pages.empty_text') }}
                <x-slot:actions><a class="ss-btn ss-btn-primary" href="{{ route('status-pages.create') }}"><svg class="ico"><use href="#i-plus"/></svg> {{ __('status_pages.create_button') }}</a></x-slot:actions>
            </x-empty-state>
        @else
            <div class="ss-table-wrap">
                <table class="ss-table ss-table-cards">
                    <thead><tr><th scope="col">{{ __('status_pages.col_name') }}</th><th scope="col">{{ __('status_pages.col_url') }}</th><th scope="col">{{ __('status_pages.col_visibility') }}</th><th scope="col" class="num">{{ __('status_pages.col_components') }}</th><th scope="col"><span class="sr-only">{{ __('status_pages.col_actions') }}</span></th></tr></thead>
                    <tbody>
                        @foreach ($pages as $page)
                            <tr>
                                <td class="is-primary"><strong>{{ $page->name }}</strong></td>
                                <td data-label="{{ __('status_pages.col_url') }}"><a class="text-accent [overflow-wrap:anywhere]" href="{{ route('status.show', $page->slug) }}" target="_blank" rel="noopener">{{ route('status.show', $page->slug) }}</a></td>
                                <td data-label="{{ __('status_pages.col_visibility') }}"><x-status :status="$page->is_public ? 'enabled' : 'paused'" :label="$page->is_public ? __('status_pages.public') : __('status_pages.private')" /></td>
                                <td data-label="{{ __('status_pages.col_components') }}" class="num">{{ $page->components_count }}</td>
                                <td class="is-actions"><div class="flex justify-end"><a class="ss-btn ss-btn-secondary ss-btn-sm" href="{{ route('status-pages.edit', $page) }}"><svg class="ico"><use href="#i-edit"/></svg> {{ __('status_pages.edit') }}</a></div></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</x-layouts.app>
