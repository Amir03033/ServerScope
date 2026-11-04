{{-- First visit: nothing monitored yet. Three steps, then the dashboard fills itself. --}}
@php
    $steps = [
        ['servers.add', 'i-server', 'server'],
        ['domains.create', 'i-globe', 'domain'],
        ['profile.edit', 'i-bell', 'notifications'],
    ];
@endphp
<section class="ss-welcome" aria-labelledby="getting-started-title">
    <div class="ss-welcome-head">
        <span class="ss-welcome-mark" aria-hidden="true"><svg class="ico"><use href="#i-pulse"/></svg></span>
        <h2 id="getting-started-title" class="ss-welcome-title">{{ __('dashboard.getting_started.title') }}</h2>
        <p class="ss-welcome-text">{{ __('dashboard.getting_started.text') }}</p>
    </div>
    <ol class="ss-welcome-steps">
        @foreach ($steps as $i => [$route, $icon, $key])
            <li>
                <a class="ss-welcome-step" href="{{ route($route) }}">
                    <span class="ss-welcome-step-icon" aria-hidden="true"><svg class="ico"><use href="#{{ $icon }}"/></svg></span>
                    <span class="ss-welcome-step-text">
                        <strong>{{ $i + 1 }}. {{ __('dashboard.getting_started.'.$key) }}</strong>
                        <span>{{ __('dashboard.getting_started.'.$key.'_text') }}</span>
                    </span>
                    <svg class="ico ss-welcome-step-arrow" aria-hidden="true"><use href="#i-chevron"/></svg>
                </a>
            </li>
        @endforeach
    </ol>
    <p class="ss-welcome-foot">{{ __('dashboard.getting_started.after') }}</p>
</section>
