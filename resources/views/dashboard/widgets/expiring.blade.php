@php
    use App\Support\Time;
@endphp
<section class="ss-panel" id="expiring">
    <div class="ss-panel-head">
        <div><h2 class="ss-panel-title">{{ __('dashboard.overview.expiring') }}</h2><p class="ss-panel-sub">{{ __('dashboard.overview.expiring_text') }}</p></div>
    </div>
    @forelse ($expirations as $item)
        <a href="{{ route('domains.show', $item['domain']) }}" class="ss-row">
            <span class="ss-chip">{{ $item['type'] === 'ssl' ? __('dashboard.overview.ssl') : __('dashboard.overview.registration') }}</span>
            <span class="ss-row-main"><strong>{{ $item['domain']->displayName() }}</strong><span class="ss-row-sub">{{ Time::date($item['at']) }}</span></span>
            <span @class(['whitespace-nowrap text-sm tabular-nums', 'ss-text-bad font-semibold' => $item['days'] < 8, 'ss-text-warn font-semibold' => $item['days'] >= 8 && $item['days'] <= 30, 'text-muted' => $item['days'] > 30])>{{ $item['days'] < 0 ? __('dashboard.overview.expired') : __('dashboard.overview.days', ['count' => $item['days']]) }}</span>
        </a>
    @empty
        <x-empty-state icon="i-calendar" :title="__('dashboard.overview.nothing_expiring')">{{ __('dashboard.overview.nothing_expiring_text') }}</x-empty-state>
    @endforelse
</section>
