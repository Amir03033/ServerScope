{{-- Texts for resources/js (see resources/js/i18n.js). --}}
@php
    $ssI18n = [
    'chart.no_data' => __('common.chart.no_data'),
    'chart.unknown' => __('common.chart.unknown'),
    'chart.cpu' => __('common.chart.cpu'),
    'chart.ram' => __('common.chart.ram'),
    'chart.load_1' => __('common.chart.load_1'),
    'chart.load_5' => __('common.chart.load_5'),
    'chart.load_15' => __('common.chart.load_15'),
    'show' => __('common.show'),
    'hide' => __('common.hide'),
    'copy' => __('common.copy'),
    'copied' => __('common.copied'),
    'search.no_results' => __('nav.search.no_results'),
    'search.all_results' => __('nav.search.all_results'),
    'search.loading' => __('nav.search.loading'),
    'search.types' => __('search.types'),
    ];
@endphp
<script type="application/json" id="ss-i18n">@json($ssI18n)</script>
