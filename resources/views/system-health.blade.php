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
</x-layouts.app>
