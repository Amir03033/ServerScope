@php use App\Support\Time; @endphp

<x-layouts.app :title="__('webhooks.title')">
    <x-slot:subtitle>{{ __('webhooks.subtitle') }}</x-slot:subtitle>
    <x-slot:actions>
        <a class="ss-btn ss-btn-secondary" href="{{ route('webhooks.create', ['type' => 'discord']) }}"><svg class="ico"><use href="#i-message"/></svg> {{ __('webhooks.connect_discord') }}</a>
        <a class="ss-btn ss-btn-primary" href="{{ route('webhooks.create') }}"><svg class="ico"><use href="#i-plus"/></svg> {{ __('webhooks.add') }}</a>
    </x-slot:actions>

    <section class="ss-panel">
        @if ($endpoints->isEmpty())
            <x-empty-state icon="i-webhook" :title="__('webhooks.empty_title')">
                {{ __('webhooks.empty_text') }}
                <x-slot:actions><a class="ss-btn ss-btn-primary" href="{{ route('webhooks.create') }}"><svg class="ico"><use href="#i-plus"/></svg> {{ __('webhooks.add') }}</a></x-slot:actions>
            </x-empty-state>
        @else
            <div class="ss-table-wrap">
                <table class="ss-table ss-table-cards">
                    <thead><tr><th scope="col">{{ __('webhooks.col_name') }}</th><th scope="col">{{ __('webhooks.col_type') }}</th><th scope="col">{{ __('webhooks.col_events') }}</th><th scope="col">{{ __('webhooks.col_status') }}</th><th scope="col">{{ __('webhooks.col_last') }}</th><th scope="col" class="num">{{ __('webhooks.col_deliveries') }}</th></tr></thead>
                    <tbody>
                        @foreach ($endpoints as $endpoint)
                            <tr>
                                <td class="is-primary"><a class="font-semibold text-fg no-underline hover:text-accent" href="{{ route('webhooks.show', $endpoint) }}">{{ $endpoint->name }}</a><span class="block text-xs text-muted">{{ parse_url($endpoint->url, PHP_URL_HOST) }}</span></td>
                                <td data-label="{{ __('webhooks.col_type') }}" class="text-sm">{{ $endpoint->isDiscord() ? __('webhooks.type_discord') : __('webhooks.type_generic') }}</td>
                                <td data-label="{{ __('webhooks.col_events') }}" class="text-sm">{{ __('webhooks.events_selected', ['count' => count($endpoint->events)]) }}</td>
                                <td data-label="{{ __('webhooks.col_status') }}">
                                    <span>
                                        <x-status :status="$endpoint->is_active ? 'enabled' : 'disabled'" />
                                        @if ($endpoint->consecutive_failures)<span class="block text-xs ss-text-bad">{{ __('webhooks.failed_in_row', ['count' => $endpoint->consecutive_failures]) }}</span>@endif
                                    </span>
                                </td>
                                <td data-label="{{ __('webhooks.col_last') }}" class="text-sm">{{ Time::relative($endpoint->last_delivery_at) }} @if ($endpoint->last_status)· {{ $endpoint->last_status === 'success' ? __('webhooks.succeeded') : __('webhooks.failed') }}@endif</td>
                                <td data-label="{{ __('webhooks.col_deliveries') }}" class="num">{{ $endpoint->deliveries_count }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</x-layouts.app>
