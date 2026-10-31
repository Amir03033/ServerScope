<?php

namespace App\Http\Controllers;

use App\Billing\BillingManager;
use App\Models\Subscription;
use App\Models\Team;
use App\Support\PlanLimits;
use App\Tenancy\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class BillingController extends Controller
{
    public function show(Request $request, BillingManager $billing): View
    {
        Gate::authorize('admin');

        // The checkout form ends up on the payment provider's domain; the
        // browser would otherwise block that redirect (CSP form-action).
        $request->attributes->set('csp.form_action', $billing->provider()->checkoutOrigins());
        $team = Team::query()->findOrFail(Tenant::id());

        return view('billing.show', [
            'cards' => $this->cards($team, $billing),
            'team' => $team,
            'enabled' => $billing->enabled(),
            'prices' => collect(['pro', 'premium'])->mapWithKeys(fn ($p) => [$p => collect(['month', 'year'])->mapWithKeys(fn ($i) => [$i => $billing->displayPrice($p, $i)])->all()])->all(),
            'subscription' => Subscription::query()->where('team_id', $team->id)->latest('id')->first(),
            'options' => collect(['pro', 'premium'])->mapWithKeys(fn ($p) => [$p => collect(['month', 'year'])->filter(fn ($i) => $billing->canCheckout($p, $i))->values()->all()]),
        ]);
    }

    public function checkout(Request $request, BillingManager $billing): RedirectResponse
    {
        Gate::authorize('admin');
        $plan = PlanLimits::normalize($request->input('plan'));
        $interval = $request->input('interval') === 'year' ? 'year' : 'month';

        if (! $billing->canCheckout($plan, $interval)) {
            return back()->with('error', __('billing.flash.unavailable'));
        }

        $team = Team::query()->findOrFail(Tenant::id());
        try {
            $url = $billing->provider()->checkoutUrl($team, $plan, $interval, route('billing.show'));
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', __('billing.flash.unavailable'));
        }

        return redirect()->away($url);
    }

    public function cancel(BillingManager $billing): RedirectResponse
    {
        Gate::authorize('admin');
        $sub = Subscription::query()->where('team_id', Tenant::id())->where('status', 'active')->latest('id')->first();

        if ($sub === null) {
            return back()->with('error', __('billing.flash.no_subscription'));
        }

        try {
            $billing->provider()->cancel($sub);
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', __('billing.flash.cancel_failed'));
        }

        $sub->update(['cancel_at_period_end' => true]);

        return back()->with('success', __('billing.flash.cancel_scheduled'));
    }

    /**
     * One card per plan: what is in it, what it costs and whether it can be bought.
     *
     * @return array<int, array<string, mixed>>
     */
    private function cards(Team $team, BillingManager $billing): array
    {
        $cards = [];

        foreach (PlanLimits::PLANS as $key) {
            $limits = (array) config("serverscope.plans.{$key}.limits", []);
            $features = (array) config("serverscope.plans.{$key}.features", []);
            $lines = [];

            foreach (['servers', 'domains', 'pages_per_website', 'status_pages'] as $limit) {
                if (isset($limits[$limit])) {
                    $lines[] = __('billing.limits.'.$limit, ['n' => number_format((int) $limits[$limit], 0, ',', '.')]);
                }
            }

            $lines[] = __('billing.limits.interval', ['n' => (int) ($limits['min_interval_minutes'] ?? 1)]);
            $lines[] = ($limits['retention_days'] ?? null) === null ? __('billing.limits.retention_unlimited') : __('billing.limits.retention', ['n' => (int) $limits['retention_days']]);

            foreach ($features as $feature) {
                $lines[] = __('billing.features.'.$feature);
            }

            $intervals = [];

            foreach (['month', 'year'] as $interval) {
                if ($billing->canCheckout($key, $interval)) {
                    $intervals[$interval] = $billing->displayPrice($key, $interval);
                }
            }

            $cards[] = [
                'key' => $key,
                'lines' => $lines,
                'intervals' => $intervals,
                'chosen' => $team->plan === $key,
                'active' => $team->plan === $key && $team->plan_status === PlanLimits::ACTIVE,
                'pending' => $team->plan === $key && $team->plan_status === PlanLimits::PENDING_PAYMENT,
            ];
        }

        return $cards;
    }
}
