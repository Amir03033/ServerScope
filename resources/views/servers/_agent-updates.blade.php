@php
    use App\Support\Label;
    use App\Support\Time;

    $u = $agentUpdate;
    $updaterStatus = ['active' => 'enabled', 'disabled' => 'paused', 'stale' => 'warning', 'missing' => 'unknown'][$u['updater']];
    $updaterLabel = __('servers.updates.updater.'.$u['updater']);
    $requestStatus = [
        'pending' => 'queued', 'in_progress' => 'investigating', 'succeeded' => 'valid',
        'rolled_back' => 'failed', 'rejected' => 'invalid', 'failed' => 'failed', 'expired' => 'expired',
    ];
    $code = fn (string $command) => '<code>'.e($command).'</code>';
@endphp

<section class="ss-panel" id="agent-updates" aria-labelledby="agent-updates-title">
    <div class="ss-panel-head">
        <div>
            <h2 id="agent-updates-title" class="ss-panel-title">{{ __('servers.updates.title') }}</h2>
            <p class="ss-panel-sub max-w-3xl">{{ __('servers.updates.text') }}</p>
        </div>
        <x-status :status="$updaterStatus" :label="$updaterLabel" />
    </div>

    <dl class="ss-dl">
        <dt>{{ __('servers.updates.installed') }}</dt><dd class="font-mono text-xs">{{ $u['installed'] ?? __('servers.unknown') }}</dd>
        <dt>{{ __('servers.updates.latest') }}</dt><dd class="font-mono text-xs">{{ $u['latest']?->version ?? __('servers.updates.no_release') }}</dd>
        <dt>{{ __('servers.updates.updater_label') }}</dt>
        <dd>
            @switch($u['updater'])
                @case('active') {{ __('servers.updates.updater_active', ['version' => $server->updater_version, 'time' => Time::relative($server->updater_last_seen_at)]) }} @break
                @case('disabled') {!! __('servers.updates.updater_disabled', ['command' => $code('sudo bash serverscope-install.sh --enable-updates')]) !!} @break
                @case('stale') {!! __('servers.updates.updater_stale', ['time' => e(Time::relative($server->updater_last_seen_at)), 'command' => $code('systemctl status serverscope-updater.timer')]) !!} @break
                @default {!! __('servers.updates.updater_missing', ['command' => $code('sudo bash serverscope-install.sh')]) !!}
            @endswitch
        </dd>
        <dt>{{ __('servers.updates.auto') }}</dt><dd>{{ $server->auto_update_enabled ? __('servers.updates.on') : __('servers.updates.off') }}</dd>
        @if ($u['request'])
            <dt>{{ __('servers.updates.last_update') }}</dt>
            <dd>
                <x-status :status="$requestStatus[$u['request']->status] ?? 'unknown'" :label="Label::of('update_status', $u['request']->status, \App\Models\AgentUpdateRequest::STATUS_LABELS)" />
                <span class="font-mono text-xs">{{ $u['request']->from_version ?? '?' }} → {{ $u['request']->target_version }}</span>
                @if ($u['request']->reason) ({{ Label::of('update_reason', $u['request']->reason, \App\Models\AgentUpdateRequest::REASONS + \App\Models\AgentUpdateRequest::SERVER_REASONS) }}) @endif
                <span class="block text-xs text-muted">
                    {{ $u['request']->source === 'auto' ? __('servers.updates.automatic') : __('servers.updates.requested_by', ['name' => $u['request']->requester?->name ?? __('servers.unknown')]) }},
                    {{ Time::relative($u['request']->created_at) }}@if ($u['request']->finished_at), {{ __('servers.updates.finished', ['time' => Time::relative($u['request']->finished_at)]) }}@endif
                </span>
            </dd>
        @endif
    </dl>

    @can('admin')
        @unless ($server->trashed())
            <div class="mt-4 flex flex-wrap items-center gap-2 border-t border-line pt-4">
                @if ($u['blocker'] === null && ! $u['request']?->isOpen())
                    <form method="POST" action="{{ route('servers.agent-update', $server) }}" data-confirm="{{ __('servers.updates.update_confirm', ['name' => $server->name, 'version' => $u['latest']->version]) }}">
                        @csrf
                        <button class="ss-btn ss-btn-primary" type="submit"><svg class="ico"><use href="#i-refresh"/></svg> {{ __('servers.updates.update_to', ['version' => $u['latest']->version]) }}</button>
                    </form>
                @elseif ($u['blocker'] && $u['latest'] && $u['latest']->isNewerThan($u['installed']))
                    <span class="text-sm text-muted">{{ $u['blocker'] }}</span>
                @endif

                <form method="POST" action="{{ route('servers.auto-update', $server) }}" class="ml-auto">
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="enabled" value="{{ $server->auto_update_enabled ? '0' : '1' }}">
                    <button class="ss-btn ss-btn-secondary" type="submit">{{ $server->auto_update_enabled ? __('servers.updates.auto_off') : __('servers.updates.auto_on') }}</button>
                </form>
            </div>
        @endunless
    @endcan
</section>
