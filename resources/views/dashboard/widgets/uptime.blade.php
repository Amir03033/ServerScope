@php
    use App\Support\Format;
@endphp
<section class="ss-panel">
    <div class="ss-panel-head">
        <div><h2 class="ss-panel-title">{{ __('dashboard.overview.uptime_per_day') }}</h2><p class="ss-panel-sub">{{ __('dashboard.overview.uptime_per_day_text') }}</p></div>
    </div>
    <div class="ss-strip">
        @foreach ($trends['daily'] as $day)
            <i @class(['up' => $day['uptime'] === 100.0, 'partial' => $day['uptime'] !== null && $day['uptime'] < 100 && $day['uptime'] >= 95, 'down' => $day['uptime'] !== null && $day['uptime'] < 95])
               title="{{ $day['uptime'] === null ? __('dashboard.overview.day_no_data', ['date' => $day['date']]) : __('dashboard.overview.day_uptime', ['date' => $day['date'], 'percent' => Format::percent($day['uptime'], 2), 'checks' => $day['checks']]) }}"></i>
        @endforeach
    </div>
    <div class="mt-1.5 flex justify-between text-xs text-muted"><span>{{ \Carbon\Carbon::parse($trends['daily'][0]['date'])->format('d-m') }}</span><span>{{ __('dashboard.overview.today') }}</span></div>
    <p class="m-0 mt-3 text-xs text-muted">{{ __('dashboard.overview.problems_now', ['http' => $dom['http_problems'], 'ssl' => $dom['ssl_problems'], 'dns' => $dom['dns_problems']]) }}</p>
</section>
