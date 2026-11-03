@php
    use App\Support\Time;
@endphp
<section class="ss-panel">
    <div class="ss-panel-head">
        <div><h2 class="ss-panel-title">{{ __('dashboard.overview.dns_changes') }}</h2><p class="ss-panel-sub">{{ __('dashboard.overview.newest_first') }}</p></div>
    </div>
    @forelse ($dnsChanges as $change)
        <a href="{{ $change->domain ? route('domains.show', $change->domain) : '#' }}" class="ss-row">
            <span class="ss-chip">{{ $change->record_type }}</span>
            <span class="ss-row-main"><strong>{{ $change->domain?->displayName() ?? __('dashboard.overview.deleted_domain') }}</strong><span class="ss-row-sub">+{{ count($change->added) }} / −{{ count($change->removed) }} · {{ Time::relative($change->detected_at) }}</span></span>
        </a>
    @empty
        <x-empty-state icon="i-dns" :title="__('dashboard.overview.no_dns_changes')">{{ __('dashboard.overview.no_dns_changes_text') }}</x-empty-state>
    @endforelse
</section>
