{{-- Every server and website at a glance; problems first. --}}
<section class="ss-panel ss-widget-panel" aria-labelledby="tiles-title">
    <div data-mission-tiles-block>
        <h2 id="tiles-title" class="ss-mission-subtitle">{{ __('mission.tiles_title') }}</h2>
        <div class="ss-mission-tiles" data-mission-tiles data-limit="8">
            @forelse ($m['tiles'] as $tile)
                <a class="ss-mtile{{ $loop->index >= 8 ? ' is-extra' : '' }}" href="{{ $tile['url'] }}" data-health="{{ $tile['health'] }}" data-key="{{ $tile['key'] }}" title="{{ $tile['name'] }}">
                    <span class="ss-mtile-dot" aria-hidden="true"></span>
                    <span class="ss-mtile-name">{{ $tile['name'] }}</span>
                    <span class="ss-mtile-value">{{ $tile['value'] === null ? '' : ($tile['type'] === 'server' ? round($tile['value']).'%' : round($tile['value']).' ms') }}</span>
                </a>
            @empty
                <p class="m-0 text-sm text-muted">{{ __('mission.tiles_empty') }}</p>
            @endforelse
        </div>
        @if (count($m['tiles']) > 8)
            <button type="button" class="ss-mission-more" data-mission-more aria-expanded="false" data-more="{{ __('mission.more', ['count' => count($m['tiles']) - 8]) }}" data-less="{{ __('mission.less') }}">{{ __('mission.more', ['count' => count($m['tiles']) - 8]) }}</button>
        @endif
    </div>
</section>
