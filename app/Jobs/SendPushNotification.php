<?php

namespace App\Jobs;

use App\Models\PushSubscription;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Push\PushSender;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/** Delivers one push message to every browser of a user; drops browsers that left. */
class SendPushNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 2;

    public function __construct(public int $userId, public array $payload, public ?string $notificationId) {}

    public function handle(): void
    {
        if (! PushSender::enabled()) {
            return;
        }

        $subscriptions = PushSubscription::query()->where('user_id', $this->userId)->get()->keyBy('endpoint');

        if ($subscriptions->isEmpty()) {
            return;
        }

        $push = new WebPush(['VAPID' => [
            'subject' => config('serverscope.push.subject'),
            'publicKey' => config('serverscope.push.public_key'),
            'privateKey' => config('serverscope.push.private_key'),
        ]], ['TTL' => 3600, 'urgency' => 'high', 'topic' => substr(preg_replace('/[^A-Za-z0-9_-]/', '', $this->payload['tag'] ?? ''), 0, 32) ?: null]);

        foreach ($subscriptions as $sub) {
            $push->queueNotification(Subscription::create([
                'endpoint' => $sub->endpoint,
                'publicKey' => $sub->public_key,
                'authToken' => $sub->auth_token,
                'contentEncoding' => $sub->content_encoding,
            ]), json_encode($this->payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }

        $sent = 0;
        $errors = [];

        foreach ($push->flush() as $report) {
            $sub = $subscriptions->get($report->getEndpoint());

            if ($report->isSuccess()) {
                $sent++;
                $sub?->forceFill(['last_used_at' => now()])->save();
            } elseif ($report->isSubscriptionExpired()) {
                // The browser unsubscribed or the push service forgot it: stop sending there.
                $sub?->delete();
            } else {
                $errors[] = mb_strimwidth((string) $report->getReason(), 0, 200);
            }
        }

        $sent > 0
            ? NotificationDispatcher::markSent($this->notificationId, 'push', (object) ['id' => $this->userId])
            : NotificationDispatcher::markFailed($this->notificationId, $errors[0] ?? 'no active browser', 'push');
    }
}
