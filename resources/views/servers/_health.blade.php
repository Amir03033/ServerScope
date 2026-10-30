@php
    use App\Models\ServiceCheck;
    use App\Support\Format;
    use App\Support\Label;
    use App\Support\Time;

    $part ??= 'diagnosis';
    $toneClass = ['green' => 'is-ok', 'yellow' => 'is-warn', 'red' => 'is-bad', 'purple' => 'is-info', 'muted' => ''][$diagnosis['tone']] ?? '';
    $agentOffline = in_array($displayStatus, ['offline', 'pending'], true);
@endphp

@if ($part === 'diagnosis')
    <div class="grid gap-4">
        <section class="ss-panel ss-diagnosis {{ $toneClass }}">
            <div class="ss-panel-head !mb-2">
                <div><h2 class="ss-panel-title">{{ __('servers.health.diagnosis', ['title' => $diagnosis['title']]) }}</h2><p class="ss-panel-sub">{{ $diagnosis['explanation'] }}</p></div>
            </div>
            @if ($diagnosis['evidence'])
                <ul class="m-0 mb-3 pl-5 text-sm">@foreach ($diagnosis['evidence'] as $line)<li>{{ $line }}</li>@endforeach</ul>
            @endif
            <dl class="ss-dl">
                <dt>{{ __('servers.health.agent') }}</dt>
                <dd>{{ $server->agent_version ?? __('servers.unknown') }}@if ($quality['outdated']) <span class="ss-text-warn">{{ __('servers.health.outdated', ['version' => $quality['latest_version']]) }}</span>@endif · {{ __('servers.health.last_seen', ['time' => Time::relative($server->last_seen_at)]) }}</dd>
                <dt>{{ __('servers.health.integrity') }}</dt>
                <dd>{{ Lang::has('servers.health.integrity_labels.'.$quality['integrity']) ? __('servers.health.integrity_labels.'.$quality['integrity']) : $quality['integrity'] }}@if ($server->agent_checksum)<span class="block font-mono text-xs text-muted">{{ substr($server->agent_checksum, 0, 16) }}…</span>@endif</dd>
                <dt>{{ __('servers.health.samples_last_hour') }}</dt>
                <dd>{{ $quality['received'] }}@if ($quality['expected']) {{ __('servers.health.expected', ['expected' => $quality['expected'], 'coverage' => Format::percent($quality['coverage'], 1), 'interval' => $quality['interval_seconds']]) }}@endif</dd>
                @if ($quality['missing_fields'])<dt>{{ __('servers.health.missing') }}</dt><dd class="ss-text-warn">{{ implode(', ', $quality['missing_fields']) }}</dd>@endif
            </dl>
        </section>

        @if ($predictions->isNotEmpty())
            <section class="ss-panel">
                <div class="ss-panel-head"><div><h2 class="ss-panel-title">{{ __('servers.health.predictions') }}</h2><p class="ss-panel-sub">{{ __('servers.health.predictions_text') }}</p></div></div>
                @include('servers._predictions', ['predictions' => $predictions])
            </section>
        @endif
    </div>
@else
    <section class="ss-panel" aria-labelledby="services-title">
        <div class="ss-panel-head">
            <div>
                <h2 id="services-title" class="ss-panel-title">{{ __('servers.health.services') }}</h2>
                <p class="ss-panel-sub">{!! __('servers.health.services_text', ['env' => '<code>SERVERSCOPE_SERVICES</code>']) !!}</p>
            </div>
        </div>
        @if ($services->isEmpty())
            <x-empty-state icon="i-list" :title="__('servers.health.no_services')">{!! __('servers.health.no_services_text', ['example' => '<code>SERVERSCOPE_SERVICES=nginx,mysql,php8.3-fpm</code>']) !!}</x-empty-state>
        @else
            <div class="ss-table-wrap">
                <table class="ss-table ss-table-cards">
                    <thead>
                        <tr>
                            <th scope="col">{{ __('servers.health.col_service') }}</th>
                            <th scope="col">{{ __('servers.health.col_status') }}</th>
                            <th scope="col">{{ __('servers.health.col_last_running') }}</th>
                            <th scope="col">{{ __('servers.health.col_notifications') }}</th>
                            @can('admin')<th scope="col"><span class="sr-only">{{ __('servers.health.col_actions') }}</span></th>@endcan
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($services as $service)
                            @php $shown = $agentOffline && $service->status !== 'stale' ? 'stale' : $service->status; @endphp
                            <tr>
                                <td class="is-primary"><strong>{{ $service->display_name ?: $service->name }}</strong><span class="block text-xs text-muted">{{ Label::of('service_kind', $service->kind, ServiceCheck::KINDS) }} · {{ $service->name }}</span></td>
                                <td data-label="{{ __('servers.health.col_status') }}">
                                    <div>
                                        @if (! $service->is_enabled)
                                            <x-status status="paused" :label="__('servers.health.disabled')" />
                                        @else
                                            <x-status :status="['running' => 'up', 'starting' => 'warning', 'stopped' => 'down', 'failed' => 'down', 'not_found' => 'down'][$shown] ?? 'unknown'" :label="Label::of('service_status', $shown, ServiceCheck::STATUS_LABELS)" />
                                            @if ($shown !== 'stale' && $service->state_detail)<span class="block text-xs text-muted">{{ $service->state_detail }}</span>@endif
                                            @if ($agentOffline)<span class="block text-xs text-muted">{{ __('servers.health.agent_offline') }}</span>@endif
                                        @endif
                                    </div>
                                </td>
                                <td data-label="{{ __('servers.health.col_last_running') }}" class="whitespace-nowrap">{{ Time::relative($service->last_running_at) }}</td>
                                <td data-label="{{ __('servers.health.col_notifications') }}" class="text-sm">@if ($service->alerts_enabled)<x-severity :severity="$service->severity" /> {{ __('servers.health.after', ['count' => $service->failure_threshold]) }}@else<span class="text-muted">{{ __('servers.health.off') }}</span>@endif</td>
                                @can('admin')
                                    <td class="is-actions">
                                        <div class="flex flex-wrap justify-end gap-1.5">
                                            <form method="POST" action="{{ route('services.update', $service) }}">
                                                @csrf @method('PUT')
                                                <input type="hidden" name="is_enabled" value="{{ $service->is_enabled ? 0 : 1 }}">
                                                <input type="hidden" name="alerts_enabled" value="{{ $service->alerts_enabled ? 1 : 0 }}">
                                                <input type="hidden" name="severity" value="{{ $service->severity }}">
                                                <input type="hidden" name="failure_threshold" value="{{ $service->failure_threshold }}">
                                                <button class="ss-btn ss-btn-secondary ss-btn-sm" type="submit">{{ $service->is_enabled ? __('servers.health.disable') : __('servers.health.enable') }}</button>
                                            </form>
                                            <form method="POST" action="{{ route('services.update', $service) }}">
                                                @csrf @method('PUT')
                                                <input type="hidden" name="is_enabled" value="{{ $service->is_enabled ? 1 : 0 }}">
                                                <input type="hidden" name="alerts_enabled" value="{{ $service->alerts_enabled ? 0 : 1 }}">
                                                <input type="hidden" name="severity" value="{{ $service->severity }}">
                                                <input type="hidden" name="failure_threshold" value="{{ $service->failure_threshold }}">
                                                <button class="ss-btn ss-btn-secondary ss-btn-sm" type="submit">{{ $service->alerts_enabled ? __('servers.health.notifications_off') : __('servers.health.notifications_on') }}</button>
                                            </form>
                                            <form method="POST" action="{{ route('services.destroy', $service) }}" data-confirm="{{ __('servers.health.delete_confirm', ['name' => $service->name]) }}">
                                                @csrf @method('DELETE')
                                                <button class="ss-btn ss-btn-danger ss-btn-sm" type="submit">{{ __('servers.health.delete') }}</button>
                                            </form>
                                        </div>
                                    </td>
                                @endcan
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
@endif
