@props(['rows' => [], 'stale' => null])

{{-- Monitoring freshness: real timestamps, relative with the absolute time on hover and for screen readers. --}}
<div {{ $attributes->class('mt-3') }} data-testid="freshness">
    <dl class="ss-dl">
        @foreach ($rows as [$label, $at])
            <dt>{{ $label }}</dt>
            <dd>
                @if ($at)
                    <time datetime="{{ $at->toIso8601String() }}" title="{{ \App\Support\Time::dateTimeSeconds($at) }}">{{ \App\Support\Time::relative($at) }}</time><span class="sr-only"> ({{ \App\Support\Time::dateTimeSeconds($at) }})</span>
                @else
                    {{ __('health.freshness.never') }}
                @endif
            </dd>
        @endforeach
    </dl>
    @if ($stale)
        <div class="ss-alert ss-alert-warning mt-3" role="status" data-stale><svg class="ico"><use href="#i-clock"/></svg><div>{{ $stale }}</div></div>
    @endif
</div>
