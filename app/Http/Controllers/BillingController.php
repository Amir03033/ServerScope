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
    public function show(BillingManager $billing): View
    {
        Gate::authorize('admin');
        $team = Team::query()->findOrFail(Tenant::id());

        return view('billing.show', [
            'team' => $team,
            'enabled' => $billing->enabled(),
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
}
