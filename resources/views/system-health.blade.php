<x-layouts.app :title="__('system_health.title')">
    <x-slot:subtitle>{{ __('system_health.subtitle') }}</x-slot:subtitle>

    <section class="ss-panel" aria-labelledby="health-title">
        <div class="ss-panel-head">
            <div>
                <h2 id="health-title" class="ss-panel-title">{{ __('system_health.overview') }}</h2>
                <p class="ss-panel-sub">{!! __('system_health.overview_text', ['docs' => '<a class="text-accent" href="'.e(config('serverscope.docs_url') ?: '#').'" target="_blank" rel="noopener">'.e(__('system_health.docs')).'</a>']) !!}</p>
            </div>
            <x-status :status="$report['status']" :label="__('system_health.overall.'.$report['status'])" />
        </div>
        <div class="ss-table-wrap">
            <table class="ss-table ss-table-cards">
                <thead><tr><th scope="col">{{ __('system_health.col_part') }}</th><th scope="col">{{ __('system_health.col_status') }}</th><th scope="col">{{ __('system_health.col_detail') }}</th></tr></thead>
                <tbody>
                    @foreach ($report['checks'] as $check)
                        <tr>
                            <td class="is-primary"><strong>{{ $check['label'] }}</strong></td>
                            <td data-label="{{ __('system_health.col_status') }}"><x-status :status="$check['status']" :label="__('system_health.check_status.'.$check['status'])" /></td>
                            <td data-label="{{ __('system_health.col_detail') }}" class="text-sm">{{ $check['detail'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    @php
        // Probe status (platform infrastructure). Counts: the whole platform
        // for the platform administrator, otherwise the own team only. No URLs.
        $probes = \App\Models\Probe::query()->orderBy('id')->get();
        $probeCounts = \App\Models\ProbeCheck::query()
            ->when(\Illuminate\Support\Facades\Gate::allows('platform'), fn ($q) => $q->withoutGlobalScopes())
            ->where('requested_at', '>=', now()->subDay())->whereNotNull('outcome')
            ->selectRaw('outcome, count(*) as n')->groupBy('outcome')->pluck('n', 'outcome');
    @endphp
    @if ($probes->isNotEmpty())
        <section class="ss-panel mt-4" aria-labelledby="probe-status-title" data-testid="probe-status">
            <div class="ss-panel-head"><div><h2 id="probe-status-title" class="ss-panel-title">{{ __('probes.title') }}</h2><p class="ss-panel-sub">{{ __('probes.text') }}</p></div></div>
            @foreach ($probes as $probe)
                <p class="m-0 mt-2 text-sm"><x-status :status="$probe->isHealthy() ? 'up' : 'down'" :label="$probe->isHealthy() ? __('probes.healthy') : __('probes.unavailable')" />
                    <strong>{{ $probe->name }}</strong>@if ($probe->location) · {{ $probe->location }}@endif
                    <span class="block text-xs text-muted">{{ __('probes.last_seen') }}: {{ $probe->last_seen_at ? \App\Support\Time::dateTime($probe->last_seen_at) : __('probes.never') }}</span></p>
            @endforeach
            <p class="m-0 mt-3 text-sm">{{ __('probes.last_day') }}:
                @foreach (\App\Models\ProbeCheck::OUTCOMES as $outcome){{ __('probes.outcome.'.$outcome) }}: {{ $probeCounts[$outcome] ?? 0 }}@if (! $loop->last) · @endif @endforeach</p>
        </section>
    @endif
</x-layouts.app>
