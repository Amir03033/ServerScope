<section class="ss-panel">
    <div class="ss-panel-head">
        <div><h2 class="ss-panel-title">{{ __('dashboard.overview.response_time') }}</h2><p class="ss-panel-sub">{{ __('dashboard.overview.response_time_text') }}</p></div>
    </div>
    <x-line-chart :points="$trends['response']" unit=" ms" :height="140" :label="__('dashboard.overview.response_time_label')" />
</section>
