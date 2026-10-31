<x-layouts.app :title="__('billing.title')">
    <x-slot:subtitle>{{ __('billing.subtitle') }}</x-slot:subtitle>

    <section class="ss-card ss-billing-summary" data-testid="billing-summary">
        <div>
            <div class="ss-billing-label">{{ __('billing.current_label') }}</div>
            <div class="ss-billing-plan">
                {{ __('plans.names.'.$team->plan) }}
                @if ($team->plan_status === 'pending_payment')
                    <span class="ss-chip ss-chip-warn">{{ __('billing.status.pending_payment') }}</span>
                @else
                    <span class="ss-chip ss-chip-ok">{{ __('billing.status.active') }}</span>
                @endif
            </div>
            @if ($subscription)
                <p class="ss-billing-meta">
                    @if ($subscription->current_period_end)
                        {{ __($subscription->cancel_at_period_end ? 'billing.ends_on' : 'billing.renews', ['date' => $subscription->current_period_end->format('Y-m-d')]) }}
                    @elseif ($subscription->cancel_at_period_end)
                        {{ __('billing.cancels') }}
                    @endif
                </p>
            @elseif ($team->plan_status === 'pending_payment')
                <p class="ss-billing-meta">{{ __('billing.pending_note') }}</p>
            @endif
        </div>
        <a class="ss-btn ss-btn-secondary ss-btn-sm" href="{{ route('billing.invoices') }}">{{ __('billing.invoices.title') }}</a>
    </section>

    @unless ($enabled)
        <div class="ss-alert ss-alert-info mt-5" role="status"><svg class="ico"><use href="#i-alert"/></svg><div>{{ __('billing.not_available') }}</div></div>
    @endunless

    <div class="ss-plan-grid" data-testid="plan-cards">
        @foreach ($cards as $card)
            @php $key = $card['key']; @endphp
            <article class="ss-card ss-plan {{ $card['chosen'] ? 'is-current' : '' }}" data-testid="plan-card" data-plan="{{ $key }}">
                <div class="ss-plan-head">
                    <h2 class="ss-plan-name">{{ __('plans.names.'.$key) }}</h2>
                    @if ($card['active'])
                        <span class="ss-chip ss-chip-ok">{{ __('billing.your_plan') }}</span>
                    @elseif ($card['pending'])
                        <span class="ss-chip ss-chip-warn">{{ __('billing.chosen') }}</span>
                    @endif
                </div>

                @if ($key === 'free')
                    <div class="ss-plan-price" data-testid="price">{{ \App\Models\Invoice::money(0) }} <span>{{ __('billing.price_per.month') }}</span></div>
                @elseif ($card['intervals'] !== [])
                    @php $first = array_key_first($card['intervals']); @endphp
                    <div class="ss-plan-price" data-testid="price">{{ $card['intervals'][$first] }} <span>{{ __('billing.price_per.'.$first) }}</span></div>
                @else
                    <div class="ss-plan-price ss-plan-price-muted" data-testid="price">{{ __('billing.price_soon') }}</div>
                @endif

                <ul class="ss-plan-list">
                    @foreach ($card['lines'] as $line)
                        <li><svg class="ico" aria-hidden="true"><use href="#i-check"/></svg><span>{{ $line }}</span></li>
                    @endforeach
                </ul>

                <div class="ss-plan-actions">
                    @if ($key === 'free')
                        <span class="ss-plan-note">{{ match (true) {
                            $team->plan === 'free' => __('billing.you_are_here'),
                            $team->plan_status === 'pending_payment' => __('billing.free_until_paid'),
                            default => __('billing.free_after_cancel'),
                        } }}</span>
                    @elseif ($card['active'])
                        <span class="ss-plan-note">{{ __('billing.you_are_here') }}</span>
                    @elseif ($enabled && $card['intervals'] !== [])
                        @foreach ($card['intervals'] as $interval => $price)
                            <form method="POST" action="{{ route('billing.checkout') }}">
                                @csrf
                                <input type="hidden" name="plan" value="{{ $key }}">
                                <input type="hidden" name="interval" value="{{ $interval }}">
                                <button type="submit" class="ss-btn {{ $loop->first ? 'ss-btn-primary' : 'ss-btn-secondary' }} w-full">
                                    @if (count($card['intervals']) > 1)
                                        {{ __('billing.choose_interval', ['interval' => __('billing.interval.'.$interval), 'price' => $price]) }}
                                    @else
                                        {{ $card['pending'] ? __('billing.pay_now') : __('billing.choose', ['plan' => __('plans.names.'.$key)]) }}
                                    @endif
                                </button>
                            </form>
                        @endforeach
                    @else
                        <button type="button" class="ss-btn ss-btn-secondary w-full" disabled>{{ __('billing.unavailable_button') }}</button>
                    @endif
                </div>
            </article>
        @endforeach
    </div>

    @if ($enabled && $subscription && $subscription->status === 'active' && ! $subscription->cancel_at_period_end)
        <section class="ss-panel ss-billing-cancel" aria-labelledby="cancel-title">
            <div>
                <h2 id="cancel-title" class="ss-panel-title">{{ __('billing.cancel_title') }}</h2>
                <p class="ss-panel-sub">{{ __('billing.cancel_note') }}</p>
            </div>
            <form method="POST" action="{{ route('billing.cancel') }}" data-confirm="{{ __('billing.cancel_confirm') }}">
                @csrf
                <button type="submit" class="ss-btn ss-btn-danger ss-btn-sm">{{ __('billing.cancel') }}</button>
            </form>
        </section>
    @endif
</x-layouts.app>
