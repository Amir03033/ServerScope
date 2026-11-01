@php
    use App\Models\Server;
    use App\Support\Format;
    use App\Support\Label;
    use App\Support\Time;

    $tabs = [
        null => ['all', $counts['all']],
        'online' => ['online', $counts['online']],
        'offline' => ['offline', $counts['offline']],
        'pending' => ['pending', $counts['pending']],
        'maintenance' => ['maintenance', $counts['maintenance']],
    ];

    if (auth()->user()->can('admin')) {
        $tabs['deleted'] = ['deleted', $counts['deleted']];
    }
@endphp

<x-layouts.app :title="__('servers.title')">
    <x-slot:subtitle>{{ __('servers.index.subtitle') }}</x-slot:subtitle>

    <x-slot:actions>
        @can('admin')
            @if ($latestRelease = \App\Models\AgentRelease::latestAvailable())
                <form method="POST" action="{{ route('servers.agent-update-all') }}" data-confirm="{{ __('servers.index.update_all_confirm', ['version' => $latestRelease->version]) }}">
                    @csrf
                    <button class="ss-btn ss-btn-secondary" type="submit"><svg class="ico"><use href="#i-refresh"/></svg> {{ __('servers.index.update_all', ['version' => $latestRelease->version]) }}</button>
                </form>
            @endif
            <a href="{{ route('servers.add') }}" class="ss-btn ss-btn-primary">
                <svg class="ico"><use href="#i-plus"/></svg> {{ __('servers.add_button') }}
            </a>
        @endcan
    </x-slot:actions>

    <section class="ss-panel">
        <nav class="ss-tabs-scroll mb-4" aria-label="{{ __('servers.index.filter_label') }}">
            <div class="ss-segmented">
                @foreach ($tabs as $key => [$label, $count])
                    <a href="{{ route('servers.index', array_filter([...request()->only(['q', 'environment', 'sort']), 'status' => $key])) }}"
                       @if ($status === $key) aria-current="page" @endif>
                        {{ __('servers.index.tabs.'.$label) }} <span class="ss-count">{{ $count }}</span>
                    </a>
                @endforeach
            </div>
        </nav>

        <form method="GET" action="{{ route('servers.index') }}" class="mb-4 grid gap-3 sm:grid-cols-[minmax(0,1fr)_auto_auto_auto] sm:items-end" role="search">
            @if ($status)
                <input type="hidden" name="status" value="{{ $status }}">
            @endif
            <div>
                <label class="ss-label" for="q">{{ __('servers.index.search') }}</label>
                <input id="q" class="ss-input mt-1.5" type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="{{ __('servers.index.search_placeholder') }}">
            </div>
            <div class="grid grid-cols-2 gap-3 sm:contents">
                <div>
                    <label class="ss-label" for="environment">{{ __('servers.index.environment') }}</label>
                    <select id="environment" class="ss-input mt-1.5" name="environment">
                        <option value="">{{ __('servers.index.all_environments') }}</option>
                        @foreach (Server::ENVIRONMENTS as $value => $label)
                            <option value="{{ $value }}" @selected(($filters['environment'] ?? null) === $value)>{{ Label::of('server_environment', $value, Server::ENVIRONMENTS) }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="ss-label" for="sort">{{ __('servers.index.sort') }}</label>
                    <select id="sort" class="ss-input mt-1.5" name="sort">
                        <option value="name" @selected(($filters['sort'] ?? 'name') === 'name')>{{ __('servers.index.sort_name') }}</option>
                        <option value="last_seen" @selected(($filters['sort'] ?? null) === 'last_seen')>{{ __('servers.index.sort_last_seen') }}</option>
                        <option value="environment" @selected(($filters['sort'] ?? null) === 'environment')>{{ __('servers.index.sort_environment') }}</option>
                    </select>
                </div>
            </div>
            <label class="flex min-h-11 items-center gap-2 text-sm"><input type="checkbox" name="favorites" value="1" @checked(! empty($filters['favorites']))> {{ __('favorites.filter') }}</label>
            <div class="flex gap-2">
                <button class="ss-btn ss-btn-secondary" type="submit"><svg class="ico"><use href="#i-search"/></svg> {{ __('servers.index.filter') }}</button>
                @if (array_filter($filters))
                    <a class="ss-btn ss-btn-ghost" href="{{ route('servers.index') }}">{{ __('servers.index.clear') }}</a>
                @endif
            </div>
        </form>

        @if ($servers->isEmpty())
            @if ($counts['all'] === 0 && ! $status)
                <x-empty-state icon="i-server" :title="__('servers.index.empty_title')">
                    {{ __('servers.index.empty_text') }}
                    @can('admin')
                        <x-slot:actions>
                            <a href="{{ route('servers.add') }}" class="ss-btn ss-btn-primary"><svg class="ico"><use href="#i-plus"/></svg> {{ __('servers.index.add_first') }}</a>
                        </x-slot:actions>
                    @endcan
                </x-empty-state>
            @else
                <x-empty-state icon="i-search" :title="__('servers.index.none_found')">
                    {{ ! empty($filters['favorites']) ? __('favorites.empty') : __('servers.index.none_found_text') }}
                </x-empty-state>
            @endif
        @else
            <div class="ss-table-wrap">
                <table class="ss-table ss-table-cards">
                    <thead>
                        <tr>
                            <th scope="col">{{ __('servers.index.col_server') }}</th>
                            <th scope="col">{{ __('servers.index.col_environment') }}</th>
                            <th scope="col">{{ __('servers.index.col_status') }}</th>
                            <th scope="col">{{ __('servers.index.col_last_contact') }}</th>
                            <th scope="col" class="num">{{ __('servers.index.col_cpu') }}</th>
                            <th scope="col" class="num">{{ __('servers.index.col_ram') }}</th>
                            <th scope="col"><span class="sr-only">{{ __('servers.index.col_actions') }}</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($servers as $server)
                            @php
                                $sample = $server->latestMetricSample;
                                $memory = $sample && $sample->memory_total_bytes > 0
                                    ? $sample->memory_used_bytes / $sample->memory_total_bytes * 100
                                    : null;
                                $state = $server->trashed() ? 'deleted' : $server->displayStatus();
                            @endphp
                            <tr>
                                <td class="is-primary">
                                    @unless ($server->trashed())<x-favorite-button type="server" :id="$server->id" :active="in_array($server->id, $favorites, true)" class="float-right" />@endunless
                                    <a class="font-semibold text-fg no-underline hover:text-accent" href="{{ route('servers.show', $server) }}">{{ $server->name }}</a>
                                    <span class="block text-xs text-muted [overflow-wrap:anywhere]">{{ $server->hostname }}@if ($server->ip_address) · {{ $server->ip_address }}@endif</span>
                                </td>
                                <td data-label="{{ __('servers.index.col_environment') }}"><span class="ss-chip">{{ Label::of('server_environment', $server->environment, Server::ENVIRONMENTS) }}</span></td>
                                <td data-label="{{ __('servers.index.col_status') }}">
                                    @if ($state === 'deleted')
                                        <x-status status="closed" :label="__('servers.deleted')" />
                                    @else
                                        <x-health :state="\App\Support\Health::server($server, $incidentSeverities[$server->id] ?? null)" />
                                    @endif
                                </td>
                                <td data-label="{{ __('servers.index.col_last_contact') }}" class="whitespace-nowrap text-muted" title="{{ Time::format($server->last_seen_at, 'd-m-Y H:i:s') }}">
                                    {{ Time::relative($server->last_seen_at) }}
                                </td>
                                <td data-label="{{ __('servers.index.col_cpu') }}" class="num">{{ $sample?->cpu_percent !== null ? Format::percent($sample->cpu_percent, 1) : '—' }}</td>
                                <td data-label="{{ __('servers.index.col_ram') }}" class="num">{{ $memory !== null ? Format::percent($memory, 1) : '—' }}</td>
                                <td class="is-actions">
                                    @php
                                        $actions = [
                                            [__('servers.actions.view'), route('servers.show', $server), 'i-server'],
                                            [__('servers.actions.metrics'), route('servers.show', $server).'#metrics', 'i-metrics'],
                                            [__('servers.actions.incidents'), route('incidents.index', ['server' => $server->id, 'state' => 'all']), 'i-siren'],
                                        ];
                                        if (auth()->user()->can('admin') && ! $server->trashed()) {
                                            $actions[] = [__('servers.actions.edit'), route('servers.edit', $server), 'i-edit'];
                                            $actions[] = [__('servers.actions.maintenance'), route('servers.show', $server).'#overview', 'i-wrench'];
                                        }
                                    @endphp
                                    <x-row-actions :label="__('servers.actions.menu', ['name' => $server->name])" :links="$actions" />
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <x-pagination :paginator="$servers" />
        @endif
    </section>
</x-layouts.app>
