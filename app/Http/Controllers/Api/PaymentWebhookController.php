<?php

namespace App\Http\Controllers\Api;

use App\Billing\BillingManager;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Throwable;

/**
 * Provider webhook. 404 unless billing is enabled; the driver verifies the
 * signature. Idempotent on the event id.
 */
class PaymentWebhookController extends Controller
{
    public function __invoke(Request $request, BillingManager $billing): Response
    {
        abort_unless($billing->enabled(), 404);

        try {
            $event = $billing->provider()->parseWebhook($request);
        } catch (Throwable) {
            return response('invalid', 400);
        }

        if ($event !== null) {
            $billing->apply($billing->provider()->name(), $event);
        }

        return response('ok', 200);
    }
}
