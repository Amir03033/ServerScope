@php
    use App\Models\WebhookEndpoint;
    use App\Support\Label;
    use App\Support\Time;

    $channels = [WebhookEndpoint::TYPE_SLACK, WebhookEndpoint::TYPE_TEAMS, WebhookEndpoint::TYPE_TELEGRAM, WebhookEndpoint::TYPE_DISCORD];
@endphp

<x-layouts.app :title="__('webhooks.title')">
    <x-slot:subtitle>{{ __('webhooks.subtitle') }}</x-slot:subtitle>
    <x-slot:actions>
        <button type="button" class="ss-btn ss-btn-secondary" popovertarget="chat-connect"><svg class="ico"><use href="#i-message"/></svg> {{ __('webhooks.connect_chat') }}</button>
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
                                <td class="is-primary"><a class="ss-hit font-semibold text-fg no-underline hover:text-accent" href="{{ route('webhooks.show', $endpoint) }}">{{ $endpoint->name }}</a><span class="block text-xs text-muted">{{ $endpoint->isChat() ? $endpoint->url : parse_url($endpoint->url, PHP_URL_HOST) }}</span></td>
                                <td data-label="{{ __('webhooks.col_type') }}" class="text-sm">{{ Label::of('webhook_type', $endpoint->type, WebhookEndpoint::TYPES) }}</td>
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

    {{-- Pick a chat channel; each has its own short form. --}}
    <div id="chat-connect" popover class="ss-sheet" role="dialog" aria-labelledby="chat-connect-title">
        <div class="grid gap-4 p-5">
            <div class="flex items-start gap-3">
                <span class="ss-sheet-icon"><svg class="ico h-[18px] w-[18px]"><use href="#i-message"/></svg></span>
                <div class="min-w-0">
                    <h2 id="chat-connect-title" class="m-0 text-base font-semibold text-fg">{{ __('webhooks.chat.title') }}</h2>
                    <p class="m-0 mt-1 text-sm text-muted">{{ __('webhooks.chat.text') }}</p>
                </div>
            </div>
            <ul class="ss-channel-list" role="list">
                @foreach ($channels as $type)
                    <li>
                        <a class="ss-channel" href="{{ route('webhooks.create', ['type' => $type]) }}" data-channel="{{ $type }}">
                            <span class="ss-channel-mark" aria-hidden="true">{{ mb_substr(WebhookEndpoint::TYPES[$type], 0, 1) }}</span>
                            <span class="min-w-0 flex-1"><strong>{{ WebhookEndpoint::TYPES[$type] }}</strong><span>{{ __('webhooks.chat.channels.'.$type) }}</span></span>
                            <svg class="ico" aria-hidden="true"><use href="#i-chevron"/></svg>
                        </a>
                    </li>
                @endforeach
            </ul>
            <div class="flex justify-end"><button type="button" class="ss-btn ss-btn-secondary" popovertarget="chat-connect" popovertargetaction="hide">{{ __('common.cancel') }}</button></div>
        </div>
    </div>
</x-layouts.app>
