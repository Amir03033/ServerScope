@php
    use App\Models\WebhookEndpoint;
    use App\Support\Label;
    use App\Support\Severity;
    use App\Support\Time;
@endphp

<x-layouts.app :title="__('webhooks.show.title', ['name' => $endpoint->name])">
    <x-slot:breadcrumb><span>›</span> <a href="{{ route('webhooks.index') }}">{{ __('webhooks.title') }}</a> <span>›</span> {{ $endpoint->name }}</x-slot:breadcrumb>
    <x-slot:pageTitle>{{ $endpoint->name }}</x-slot:pageTitle>
    <x-slot:subtitle>{{ $endpoint->url }}</x-slot:subtitle>
    <x-slot:actions>
        <form method="POST" action="{{ route('webhooks.test', $endpoint) }}">@csrf<button class="ss-btn ss-btn-secondary" type="submit"><svg class="ico"><use href="#i-play"/></svg> {{ $endpoint->isDiscord() ? __('webhooks.show.test_discord') : __('webhooks.show.test') }}</button></form>
        <a class="ss-btn ss-btn-secondary" href="{{ route('webhooks.edit', $endpoint) }}"><svg class="ico"><use href="#i-edit"/></svg> {{ __('webhooks.show.edit') }}</a>
    </x-slot:actions>

    <div class="grid gap-4">
        @if (session('webhook_secret'))
            <x-secret-token :token="session('webhook_secret')" :title="__('webhooks.show.secret_title')" id="webhook-secret">
                <p class="mt-2 mb-0 text-sm">{!! __('webhooks.show.secret_help', ['signature' => '<code>X-ServerScope-Signature</code>', 'prefix' => '<code>sha256=</code>', 'payload' => '<code>X-ServerScope-Timestamp + "." + body</code>']) !!}</p>
            </x-secret-token>
        @endif

        <div class="grid gap-4 lg:grid-cols-[minmax(0,2fr)_minmax(0,3fr)] lg:items-start">
            <section class="ss-panel" aria-labelledby="settings-title">
                <div class="ss-panel-head"><div><h2 id="settings-title" class="ss-panel-title">{{ __('webhooks.show.settings') }}</h2></div><x-status :status="$endpoint->is_active ? 'enabled' : 'disabled'" /></div>
                <dl class="ss-dl">
                    <dt>{{ __('webhooks.show.type') }}</dt><dd>{{ Label::of('webhook_type', $endpoint->type, WebhookEndpoint::TYPES) }}</dd>
                    <dt>{{ __('webhooks.show.min_severity') }}</dt><dd>{{ $endpoint->min_severity ? __('webhooks.form.and_higher', ['severity' => Label::of('severity', $endpoint->min_severity, Severity::LABELS)]) : __('webhooks.form.all_incidents') }}</dd>
                    <dt>{{ __('webhooks.show.events') }}</dt><dd><span class="flex flex-wrap gap-1">@foreach ($endpoint->events as $e)<code class="ss-chip">{{ $e }}</code>@endforeach</span></dd>
                    <dt>{{ __('webhooks.show.failed_in_row') }}</dt><dd>{{ __('webhooks.show.disabled_after', ['count' => $endpoint->consecutive_failures, 'max' => \App\Jobs\DeliverWebhook::DISABLE_AFTER_FAILURES]) }}</dd>
                    <dt>{{ __('webhooks.show.last_delivery') }}</dt><dd>{{ $endpoint->last_delivery_at ? Time::dateTime($endpoint->last_delivery_at) : '—' }}</dd>
                </dl>
                <div class="mt-5 flex flex-wrap gap-2 border-t border-line pt-4">
                    @if ($endpoint->isDiscord())
                        <a class="ss-btn ss-btn-secondary" href="{{ route('webhooks.edit', $endpoint) }}"><svg class="ico"><use href="#i-refresh"/></svg> {{ __('webhooks.show.replace_discord') }}</a>
                    @else
                        <form method="POST" action="{{ route('webhooks.secret', $endpoint) }}" data-confirm="{{ __('webhooks.show.rotate_confirm') }}">@csrf<button class="ss-btn ss-btn-secondary" type="submit"><svg class="ico"><use href="#i-key"/></svg> {{ __('webhooks.show.rotate') }}</button></form>
                    @endif
                    <form method="POST" action="{{ route('webhooks.destroy', $endpoint) }}" data-confirm="{{ __('webhooks.show.delete_confirm') }}">@csrf @method('DELETE')<button class="ss-btn ss-btn-danger" type="submit"><svg class="ico"><use href="#i-trash"/></svg> {{ __('webhooks.show.delete') }}</button></form>
                </div>
            </section>

            <section class="ss-panel" aria-labelledby="deliveries-title">
                <div class="ss-panel-head"><div><h2 id="deliveries-title" class="ss-panel-title">{{ __('webhooks.show.deliveries') }}</h2><p class="ss-panel-sub">{{ __('webhooks.show.deliveries_text') }}</p></div></div>
                @if ($deliveries->isEmpty())
                    <x-empty-state icon="i-history" :title="__('webhooks.show.deliveries_empty_title')">{{ __('webhooks.show.deliveries_empty_text') }}</x-empty-state>
                @else
                    <div class="ss-table-wrap">
                        <table class="ss-table ss-table-cards">
                            <thead><tr><th scope="col">{{ __('webhooks.show.col_time') }}</th><th scope="col">{{ __('webhooks.show.col_event') }}</th><th scope="col">{{ __('webhooks.show.col_status') }}</th><th scope="col" class="num">{{ __('webhooks.show.col_http') }}</th><th scope="col" class="num">{{ __('webhooks.show.col_attempts') }}</th><th scope="col"><span class="sr-only">{{ __('webhooks.show.col_actions') }}</span></th></tr></thead>
                            <tbody>
                                @foreach ($deliveries as $delivery)
                                    <tr>
                                        <td class="is-primary whitespace-nowrap">{{ Time::dateTimeSeconds($delivery->created_at) }}<span class="block font-mono text-xs text-muted">{{ $delivery->uuid }}</span></td>
                                        <td data-label="{{ __('webhooks.show.col_event') }}"><code>{{ $delivery->event }}</code></td>
                                        <td data-label="{{ __('webhooks.show.col_status') }}">
                                            <span>
                                                <x-status :status="['success' => 'sent', 'failed' => 'failed', 'pending' => 'queued', 'retrying' => 'warning'][$delivery->status] ?? 'unknown'" :label="Lang::has('webhooks.show.status.'.$delivery->status) ? __('webhooks.show.status.'.$delivery->status) : $delivery->status" />
                                                @if ($delivery->error)<span class="block text-xs text-muted [overflow-wrap:anywhere]">{{ $delivery->error }}</span>@endif
                                            </span>
                                        </td>
                                        <td data-label="{{ __('webhooks.show.col_http') }}" class="num">{{ $delivery->response_status ?? '—' }}</td>
                                        <td data-label="{{ __('webhooks.show.col_attempts') }}" class="num">{{ $delivery->attempts }}</td>
                                        <td class="is-actions">
                                            @if ($delivery->status === 'failed')
                                                <form method="POST" action="{{ route('webhooks.redeliver', [$endpoint, $delivery]) }}" class="flex justify-end">@csrf<button class="ss-btn ss-btn-secondary ss-btn-sm" type="submit">{{ __('webhooks.show.redeliver') }}</button></form>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <x-pagination :paginator="$deliveries" />
                @endif
            </section>
        </div>
    </div>
</x-layouts.app>
