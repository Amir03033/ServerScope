{{-- Needs attention --}}
<section class="mb-6" aria-labelledby="attention-title">
    <h2 id="attention-title" class="ss-section-title mb-3">{{ __('dashboard.attention.title') }}</h2>
    @if ($attention === [])
        <div class="ss-alert ss-alert-success" role="status">
            <svg class="ico"><use href="#i-check"/></svg>
            <div><strong>{{ __('dashboard.attention.all_good') }}</strong> <span class="text-muted">{{ __('dashboard.attention.all_good_text') }}</span></div>
        </div>
    @else
        <div class="ss-attention">
            @foreach ($attention as $item)
                <a class="ss-attention-item {{ $item['tone'] }}" href="{{ $item['url'] }}">
                    <span class="ss-attention-count">{{ $item['count'] }}</span>
                    <span class="min-w-0 flex-1">
                        <span class="block text-sm font-semibold">{{ $item['label'] }}</span>
                        <span class="block truncate text-xs text-muted">{{ $item['sub'] }}</span>
                    </span>
                    <svg class="ico"><use href="#{{ $item['icon'] }}"/></svg>
                </a>
            @endforeach
        </div>
    @endif
</section>

@if ($servers->isEmpty())
    {{-- First steps when there is nothing to monitor yet. --}}
    <section class="ss-panel mb-6" aria-labelledby="getting-started-title">
        <h2 id="getting-started-title" class="ss-panel-title">{{ __('dashboard.getting_started.title') }}</h2>
        <p class="ss-panel-sub">{{ __('dashboard.getting_started.text') }}</p>
        <ol class="m-0 mt-4 grid list-none gap-3 p-0 md:grid-cols-3">
            @foreach ([['servers.add', 'i-server', 'server'], ['domains.create', 'i-globe', 'domain'], ['profile.edit', 'i-bell', 'notifications']] as $i => [$route, $icon, $key])
                <li>
                    <a class="ss-stat h-full" href="{{ route($route) }}">
                        <span class="ss-stat-icon"><svg class="ico"><use href="#{{ $icon }}"/></svg></span>
                        <span class="min-w-0">
                            <span class="block text-sm font-semibold">{{ $i + 1 }}. {{ __('dashboard.getting_started.'.$key) }}</span>
                            <span class="mt-0.5 block text-xs text-muted">{{ __('dashboard.getting_started.'.$key.'_text') }}</span>
                        </span>
                    </a>
                </li>
            @endforeach
        </ol>
    </section>
@endif
