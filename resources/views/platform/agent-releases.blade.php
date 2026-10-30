@php use App\Support\Time; @endphp

<x-layouts.app :title="__('admin.releases.title')">
    <x-slot:subtitle>{!! __('admin.releases.subtitle', ['import' => '<code>php artisan serverscope:release:import</code>', 'withdraw' => '<code>serverscope:release:withdraw</code>']) !!}</x-slot:subtitle>

    <div class="grid gap-4">
        <section class="ss-panel" aria-labelledby="releases-title">
            <div class="ss-panel-head"><div><h2 id="releases-title" class="ss-panel-title">{{ __('admin.releases.releases_title') }}</h2></div></div>
            @if ($releases->isEmpty())
                <x-empty-state icon="i-pulse" :title="__('admin.releases.empty_title')">{{ __('admin.releases.empty_text') }}</x-empty-state>
            @else
                <div class="ss-table-wrap">
                    <table class="ss-table ss-table-cards">
                        <thead><tr><th scope="col">{{ __('admin.releases.col_version') }}</th><th scope="col">{{ __('admin.releases.col_date') }}</th><th scope="col">{{ __('admin.releases.col_key') }}</th><th scope="col">{{ __('admin.releases.col_commit') }}</th><th scope="col">{{ __('admin.releases.col_sha') }}</th><th scope="col">{{ __('admin.releases.col_status') }}</th></tr></thead>
                        <tbody>
                            @foreach ($releases as $release)
                                <tr>
                                    <td class="is-primary"><strong>{{ $release->version }}</strong></td>
                                    <td data-label="{{ __('admin.releases.col_date') }}" class="whitespace-nowrap">{{ Time::dateTime($release->released_at) }}</td>
                                    <td data-label="{{ __('admin.releases.col_key') }}" class="font-mono text-xs">{{ $release->key_id }}</td>
                                    <td data-label="{{ __('admin.releases.col_commit') }}" class="font-mono text-xs" title="{{ $release->commit }}">{{ substr($release->commit, 0, 12) }}</td>
                                    <td data-label="{{ __('admin.releases.col_sha') }}" class="font-mono text-xs" title="{{ $release->sha256 }}">{{ substr($release->sha256, 0, 16) }}…</td>
                                    <td data-label="{{ __('admin.releases.col_status') }}">
                                        <span>
                                            @if ($release->withdrawn_at)
                                                <x-status status="expired" :label="__('admin.releases.withdrawn')" /> <span class="block text-xs text-muted">{{ $release->withdraw_reason }}</span>
                                            @else
                                                <x-status status="valid" :label="__('admin.releases.available')" />
                                            @endif
                                        </span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        <section class="ss-panel" aria-labelledby="versions-title">
            <div class="ss-panel-head"><div><h2 id="versions-title" class="ss-panel-title">{{ __('admin.releases.versions_title') }}</h2><p class="ss-panel-sub">{{ __('admin.releases.versions_text', ['count' => $total]) }}</p></div></div>
            <div class="ss-table-wrap">
                <table class="ss-table ss-table-cards">
                    <thead><tr><th scope="col">{{ __('admin.releases.col_agent_version') }}</th><th scope="col" class="num">{{ __('admin.releases.col_servers') }}</th><th scope="col" class="num">{{ __('admin.releases.col_with_updater') }}</th></tr></thead>
                    <tbody>
                        @foreach ($versions as $row)
                            <tr><td class="is-primary">{{ $row['version'] === 'onbekend' ? __('servers.unknown') : $row['version'] }}</td><td data-label="{{ __('admin.releases.col_servers') }}" class="num">{{ $row['servers'] }}</td><td data-label="{{ __('admin.releases.col_with_updater') }}" class="num">{{ $row['with_updater'] }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</x-layouts.app>
