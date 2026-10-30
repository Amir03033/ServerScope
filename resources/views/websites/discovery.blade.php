@php
    use App\Support\Time;

    $isAdmin = auth()->user()->can('admin');
    $running = $runs->getCollection()->contains(fn ($run) => $run->isRunning());
    $lines = fn (array $values) => implode("\n", $values);
    $statusPill = ['completed' => 'valid', 'partial' => 'warning', 'failed' => 'failed', 'queued' => 'queued', 'running' => 'investigating'];
    $runLabel = fn (string $status) => Lang::has('websites.run_status.'.$status) ? __('websites.run_status.'.$status) : $status;
    $maxPages = config('serverscope.websites.discovery_default_max_pages');
@endphp

<x-layouts.app :title="__('websites.discovery.title', ['name' => $domain->displayName()])">
    <x-slot:breadcrumb><span>›</span> <a href="{{ route('domains.show', $domain) }}">{{ $domain->displayName() }}</a> <span>›</span> {{ __('websites.discovery.crumb') }}</x-slot:breadcrumb>
    <x-slot:subtitle>{{ __('websites.discovery.subtitle', ['domain' => $domain->name]) }}</x-slot:subtitle>
    <x-slot:actions>
        @if ($isAdmin)
            <form method="POST" action="{{ route('websites.discovery.run', $domain) }}">
                @csrf
                <button class="ss-btn ss-btn-primary whitespace-nowrap" type="submit" @disabled($running)><svg class="ico"><use href="#i-search"/></svg> {{ $running ? __('websites.discovery.running') : __('websites.discovery.run') }}</button>
            </form>
        @endif
    </x-slot:actions>
    @if ($running)
        <x-slot:head><noscript><meta http-equiv="refresh" content="10"></noscript></x-slot:head>
    @endif

    @include('websites._tabs')

    <div class="grid gap-4">
        @if ($running)
            <x-auto-refresh :seconds="10" />
        @endif

        @if ($pendingCount > 0)
            <div class="ss-alert ss-alert-info" role="status"><svg class="ico"><use href="#i-search"/></svg><div><strong>{{ trans_choice('websites.discovery.pending_notice', $pendingCount, ['count' => $pendingCount]) }}</strong> <a class="text-accent" href="{{ route('websites.pages', [$domain, 'status' => 'pending']) }}">{{ __('websites.discovery.review') }}</a></div></div>
        @endif

        <div class="grid gap-4 lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
            <section class="ss-panel" aria-labelledby="runs-title">
                <div class="ss-panel-head"><div><h2 id="runs-title" class="ss-panel-title">{{ __('websites.discovery.runs_title') }}</h2><p class="ss-panel-sub">{{ __('websites.discovery.runs_text') }}</p></div></div>
                @if ($runs->isEmpty())
                    <x-empty-state icon="i-search" :title="__('websites.discovery.empty_title')">{{ __('websites.discovery.empty_text') }}</x-empty-state>
                @else
                    <div class="ss-table-wrap">
                        <table class="ss-table ss-table-cards">
                            <thead><tr><th scope="col">{{ __('websites.discovery.col_started') }}</th><th scope="col">{{ __('websites.discovery.col_status') }}</th><th scope="col" class="num">{{ __('websites.discovery.col_found') }}</th><th scope="col" class="num">{{ __('websites.discovery.col_new') }}</th><th scope="col" class="num">{{ __('websites.discovery.col_skipped') }}</th><th scope="col" class="num">{{ __('websites.discovery.col_requests') }}</th></tr></thead>
                            <tbody>
                                @foreach ($runs as $run)
                                    <tr>
                                        <td class="is-primary whitespace-nowrap">{{ Time::dateTime($run->created_at) }}<span class="block text-xs text-muted">{{ $run->triggeredBy?->name ?? __('websites.discovery.system') }}@if ($run->finished_at){{ __('websites.discovery.seconds', ['seconds' => max(0, (int) $run->started_at?->diffInSeconds($run->finished_at))]) }}@endif</span></td>
                                        <td data-label="{{ __('websites.discovery.col_status') }}">
                                            <span>
                                                <x-status :status="$statusPill[$run->status] ?? 'unknown'" :label="$runLabel($run->status)" />
                                                @if ($run->sources)<span class="block text-xs text-muted">@foreach ($run->sources as $source => $n){{ $source }}: {{ $n }} @endforeach</span>@endif
                                            </span>
                                        </td>
                                        <td data-label="{{ __('websites.discovery.col_found') }}" class="num">{{ $run->urls_found }}</td>
                                        <td data-label="{{ __('websites.discovery.col_new') }}" class="num">{{ $run->pages_added }}</td>
                                        <td data-label="{{ __('websites.discovery.col_skipped') }}" class="num">{{ $run->urls_skipped }}</td>
                                        <td data-label="{{ __('websites.discovery.col_requests') }}" class="num">{{ $run->requests_made }}</td>
                                    </tr>
                                    @if ($run->errors)
                                        <tr>
                                            <td colspan="6" class="is-primary text-xs">
                                                <details>
                                                    <summary class="cursor-pointer">{{ __('websites.discovery.messages', ['count' => count($run->errors)]) }}</summary>
                                                    <ul class="mt-1.5 mb-0 pl-4">@foreach ($run->errors as $error)<li class="[overflow-wrap:anywhere]">{{ $error }}</li>@endforeach</ul>
                                                </details>
                                            </td>
                                        </tr>
                                    @endif
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <x-pagination :paginator="$runs" />
                @endif
            </section>

            <section class="ss-panel" aria-labelledby="settings-title">
                <div class="ss-panel-head"><div><h2 id="settings-title" class="ss-panel-title">{{ __('websites.discovery.settings_title') }}</h2><p class="ss-panel-sub">{{ __('websites.discovery.settings_text', ['max' => $maxPages, 'domain' => $domain->name]) }}</p></div></div>
                @if ($isAdmin)
                    @php $field = fn (string $name) => $errors->has($name) ? 'aria-invalid=true aria-describedby='.$name.'-error' : ''; @endphp
                    <form method="POST" action="{{ route('websites.discovery.update', $domain) }}" class="grid gap-4">
                        @csrf @method('PUT')
                        <div class="grid gap-4 sm:grid-cols-3">
                            <div>
                                <label class="ss-label" for="max_pages">{{ __('websites.discovery.max_pages') }}</label>
                                <input id="max_pages" class="ss-input mt-1.5" name="max_pages" type="number" min="1" max="{{ config('serverscope.websites.discovery_max_pages_limit') }}" value="{{ old('max_pages', $settings['max_pages']) }}" {{ $field('max_pages') }}>
                                @error('max_pages')<p id="max_pages-error" class="ss-error">{{ $message }}</p>@enderror
                            </div>
                            <div>
                                <label class="ss-label" for="max_depth">{{ __('websites.discovery.max_depth') }}</label>
                                <input id="max_depth" class="ss-input mt-1.5" name="max_depth" type="number" min="0" max="5" value="{{ old('max_depth', $settings['max_depth']) }}" {{ $field('max_depth') }}>
                                <p class="ss-help">{{ __('websites.discovery.max_depth_help') }}</p>
                                @error('max_depth')<p id="max_depth-error" class="ss-error">{{ $message }}</p>@enderror
                            </div>
                            <div>
                                <label class="ss-label" for="default_interval">{{ __('websites.discovery.default_interval') }}</label>
                                <input id="default_interval" class="ss-input mt-1.5" name="default_interval" type="number" min="1" max="1440" value="{{ old('default_interval', $settings['default_interval']) }}" {{ $field('default_interval') }}>
                                @error('default_interval')<p id="default_interval-error" class="ss-error">{{ $message }}</p>@enderror
                            </div>
                        </div>
                        <div>
                            <label class="ss-label" for="sitemap_urls">{{ __('websites.discovery.sitemap_urls') }}</label>
                            <textarea id="sitemap_urls" class="ss-input mt-1.5" name="sitemap_urls" rows="2" placeholder="https://{{ $domain->name }}/sitemap_index.xml" {{ $field('sitemap_urls') }}>{{ old('sitemap_urls', $lines($settings['sitemap_urls'])) }}</textarea>
                            @error('sitemap_urls')<p id="sitemap_urls-error" class="ss-error">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label class="ss-label" for="allowed_patterns">{{ __('websites.discovery.allowed_patterns') }}</label>
                            <textarea id="allowed_patterns" class="ss-input mt-1.5" name="allowed_patterns" rows="2" placeholder="/docs/*">{{ old('allowed_patterns', $lines($settings['allowed_patterns'])) }}</textarea>
                        </div>
                        <div>
                            <label class="ss-label" for="excluded_patterns">{{ __('websites.discovery.excluded_patterns') }}</label>
                            <textarea id="excluded_patterns" class="ss-input mt-1.5" name="excluded_patterns" rows="2" placeholder="/wp-admin/*&#10;*.pdf">{{ old('excluded_patterns', $lines($settings['excluded_patterns'])) }}</textarea>
                            <p class="ss-help">{{ __('websites.discovery.excluded_help') }}</p>
                        </div>
                        <div class="grid gap-2.5">
                            <label class="ss-check"><input type="hidden" name="keep_query" value="0"><input type="checkbox" name="keep_query" value="1" @checked(old('keep_query', $settings['keep_query']))> {{ __('websites.discovery.keep_query') }}</label>
                            <label class="ss-check"><input type="hidden" name="include_subdomains" value="0"><input type="checkbox" name="include_subdomains" value="1" @checked(old('include_subdomains', $settings['include_subdomains']))> {{ __('websites.discovery.include_subdomains') }}</label>
                            <label class="ss-check"><input type="hidden" name="auto_monitor" value="0"><input type="checkbox" name="auto_monitor" value="1" @checked(old('auto_monitor', $settings['auto_monitor']))> {{ __('websites.discovery.auto_monitor') }}</label>
                        </div>
                        <div class="border-t border-line pt-4">
                            <label class="ss-check"><input type="checkbox" name="broad_confirmed" value="1" {{ $field('broad_confirmed') }}> {{ __('websites.discovery.broad_confirmed') }}</label>
                            <p class="ss-help">{{ __('websites.discovery.broad_help', ['max' => $maxPages]) }}</p>
                            @error('broad_confirmed')<p id="broad_confirmed-error" class="ss-error">{{ $message }}</p>@enderror
                        </div>
                        <div><button class="ss-btn ss-btn-primary" type="submit">{{ __('websites.discovery.save') }}</button></div>
                    </form>
                @else
                    <dl class="ss-dl">
                        <dt>{{ __('websites.discovery.pages_depth') }}</dt><dd>{{ $settings['max_pages'] }} / {{ $settings['max_depth'] }}</dd>
                        <dt>{{ __('websites.discovery.subdomains') }}</dt><dd>{{ $settings['include_subdomains'] ? __('websites.yes') : __('websites.no') }}</dd>
                        <dt>{{ __('websites.discovery.monitor_directly') }}</dt><dd>{{ $settings['auto_monitor'] ? __('websites.yes') : __('websites.discovery.with_approval') }}</dd>
                    </dl>
                @endif
            </section>
        </div>
    </div>
</x-layouts.app>
