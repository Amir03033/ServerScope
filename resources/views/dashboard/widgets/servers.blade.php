@php
    use App\Support\Format;
@endphp
{{-- Servers --}}
<section class="ss-panel" id="servers" aria-labelledby="servers-title">
    <div class="ss-panel-head">
        <div>
            <h2 id="servers-title" class="ss-panel-title">{{ __('dashboard.servers.title') }}</h2>
            <p class="ss-panel-sub">{{ __('dashboard.servers.text') }}</p>
        </div>
        <span class="text-xs text-muted">{{ __('dashboard.servers.total', ['count' => $serverCount]) }}</span>
    </div>

    @if ($servers->isEmpty())
        <x-empty-state icon="i-server" :title="__('dashboard.servers.empty_title')">
            {{ __('dashboard.servers.empty_text') }}
            @can('admin')
                <x-slot:actions>
                    <a class="ss-btn ss-btn-primary" href="{{ route('servers.add') }}"><svg class="ico"><use href="#i-plus"/></svg> {{ __('dashboard.servers.add_first') }}</a>
                </x-slot:actions>
            @endcan
        </x-empty-state>
    @else
        <div class="ss-table-wrap">
            <table class="ss-table ss-table-cards">
                <thead>
                    <tr>
                        <th scope="col">{{ __('dashboard.servers.col_server') }}</th>
                        <th scope="col">{{ __('dashboard.servers.col_status') }}</th>
                        <th scope="col">{{ __('dashboard.servers.col_contact') }}</th>
                        <th scope="col">{{ __('dashboard.servers.col_agent') }}</th>
                        <th scope="col" class="w-40">{{ __('dashboard.servers.col_cpu') }}</th>
                        <th scope="col" class="w-40">{{ __('dashboard.servers.col_ram') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($servers as $server)
                        @php
                            $sample = $server->latestMetricSample;
                            $serverCpu = $sample?->cpu_percent;
                            $statusTone = ['online' => 'ss-status-ok', 'offline' => 'ss-status-bad', 'maintenance' => 'ss-status-info'][$server->display_status] ?? 'ss-status-muted';
                            $statusKey = in_array($server->display_status, ['online', 'offline', 'maintenance'], true) ? $server->display_status : 'pending';
                        @endphp
                        <tr>
                            <td class="is-primary">
                                <a class="ss-hit font-semibold text-fg no-underline hover:text-accent" href="{{ route('servers.show', $server) }}">{{ $server->name }}</a>
                                <span class="block text-xs text-muted">{{ $server->hostname }}@if ($server->operating_system) · {{ $server->operating_system }}@endif</span>
                            </td>
                            <td data-label="{{ __('dashboard.servers.col_status') }}"><span class="ss-status {{ $statusTone }}">{{ __('dashboard.servers.status.'.$statusKey) }}</span></td>
                            <td data-label="{{ __('dashboard.servers.col_contact') }}" class="whitespace-nowrap text-muted" title="{{ $sample ? __('dashboard.servers.measured', ['time' => $sample->collected_at?->diffForHumans() ?? __('dashboard.servers.unknown_time')]) : __('dashboard.servers.no_samples') }}">{{ $server->last_seen_at?->diffForHumans() ?? __('dashboard.system.never') }}</td>
                            <td data-label="{{ __('dashboard.servers.col_agent') }}" class="text-muted">{{ $server->agent_version ?? __('dashboard.servers.unknown') }}</td>
                            <td data-label="{{ __('dashboard.servers.col_cpu') }}">
                                @if ($sample)
                                    <div class="flex items-center gap-2"><span class="w-12 text-right tabular-nums">{{ $serverCpu !== null ? Format::percent($serverCpu, 1) : __('dashboard.servers.no_data') }}</span><span class="min-w-16 flex-1">{!! $bar($serverCpu !== null ? (float) $serverCpu : null) !!}</span></div>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td data-label="{{ __('dashboard.servers.col_ram') }}">
                                @if ($sample)
                                    <div class="flex items-center gap-2"><span class="w-12 text-right tabular-nums">{{ $server->memory_percent !== null ? Format::percent($server->memory_percent, 1) : __('dashboard.servers.no_data') }}</span><span class="min-w-16 flex-1">{!! $bar($server->memory_percent) !!}</span></div>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>
