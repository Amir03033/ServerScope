<?php

namespace App\Http\Controllers;

use App\Models\PushSubscription;
use App\Services\Push\PushSender;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Turn push notifications on or off for this browser (profile page). */
class PushSubscriptionController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        abort_unless(PushSender::enabled(), 404);

        $data = $request->validate([
            'endpoint' => ['required', 'string', 'url:https', 'max:2000'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
            'contentEncoding' => ['nullable', 'in:aes128gcm,aesgcm'],
            'device' => ['nullable', 'string', 'max:120'],
        ]);

        // One row per browser; a browser that moves to another account moves with it.
        PushSubscription::query()->updateOrCreate(
            ['endpoint_hash' => PushSubscription::hashOf($data['endpoint'])],
            [
                'user_id' => $request->user()->id,
                'endpoint' => $data['endpoint'],
                'public_key' => $data['keys']['p256dh'],
                'auth_token' => $data['keys']['auth'],
                'content_encoding' => $data['contentEncoding'] ?? 'aes128gcm',
                'device' => $data['device'] ?? null,
            ],
        );

        return response()->json(['ok' => true, 'devices' => $request->user()->pushSubscriptions()->count()]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $endpoint = (string) $request->validate(['endpoint' => ['required', 'string', 'max:2000']])['endpoint'];

        $request->user()->pushSubscriptions()->where('endpoint_hash', PushSubscription::hashOf($endpoint))->delete();

        return response()->json(['ok' => true, 'devices' => $request->user()->pushSubscriptions()->count()]);
    }
}
