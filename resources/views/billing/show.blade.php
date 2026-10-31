<x-layouts.app :title="__('billing.title')">
    <div class="ss-card p-5">
        <p class="m-0">{{ __('billing.current', ['plan' => __('plans.names.'.$team->plan), 'status' => __('billing.status.'.$team->plan_status)]) }}</p>
        @if ($subscription)
            <p class="mt-2 text-sm text-muted">
                {{ __('billing.subscription', ['status' => __('billing.sub_status.'.$subscription->status)]) }}
                @if ($subscription->current_period_end) · {{ __('billing.renews', ['date' => $subscription->current_period_end->format('Y-m-d')]) }} @endif
                @if ($subscription->cancel_at_period_end) · {{ __('billing.cancels') }} @endif
            </p>
        @endif
    </div>

    <p class="mt-3 text-sm"><a class="text-accent" href="{{ route('billing.invoices') }}">{{ __('billing.invoices.title') }}</a></p>

    @if (! $enabled)
        <div class="ss-alert ss-alert-info mt-5" role="status"><svg class="ico"><use href="#i-alert"/></svg><div>{{ __('billing.not_available') }}</div></div>
    @else
        <div class="mt-5 grid gap-4 sm:grid-cols-2">
            @foreach ($options as $plan => $intervals)
                @foreach ($intervals as $interval)
                    <form method="POST" action="{{ route('billing.checkout') }}" class="ss-card p-5">
                        @csrf
                        <input type="hidden" name="plan" value="{{ $plan }}">
                        <input type="hidden" name="interval" value="{{ $interval }}">
                        <strong>{{ __('plans.names.'.$plan) }}</strong> · {{ __('billing.interval.'.$interval) }}
                        @if ($prices[$plan][$interval] ?? null)
                            <div class="mt-1 text-lg font-semibold" data-testid="price">{{ $prices[$plan][$interval] }} <span class="text-sm font-normal text-muted">{{ __('billing.price_per.'.$interval) }}</span></div>
                        @endif
                        <button type="submit" class="ss-btn ss-btn-primary mt-3 w-full">{{ __('billing.pay') }}</button>
                    </form>
                @endforeach
            @endforeach
        </div>
        @if ($subscription && $subscription->status === 'active' && ! $subscription->cancel_at_period_end)
            <form method="POST" action="{{ route('billing.cancel') }}" class="mt-5">
                @csrf
                <button type="submit" class="ss-btn">{{ __('billing.cancel') }}</button>
                <p class="mt-2 text-sm text-muted">{{ __('billing.cancel_note') }}</p>
            </form>
        @endif
    @endif
</x-layouts.app>
