<?php

namespace App\Services\Push;

use App\Jobs\SendPushNotification;
use App\Models\User;
use App\Support\Locale;
use Illuminate\Notifications\Notification;

/**
 * Web push as an extra channel next to e-mail and in-app: when a user gets
 * a notification (same recipients, same preferences, same moment), every
 * browser on which they turned on push gets a short message with a link.
 * Off while the VAPID keys are not set. The message is built here, in the
 * user's language; the job only delivers it.
 */
class PushSender
{
    public static function enabled(): bool
    {
        return filled(config('serverscope.push.public_key')) && filled(config('serverscope.push.private_key'));
    }

    public function willSend(User $user): bool
    {
        return self::enabled() && ! $user->is_demo && $user->pushSubscriptions()->exists();
    }

    public function send(User $user, Notification $notification, ?string $notificationId): void
    {
        if (! $this->willSend($user) || ! method_exists($notification, 'toArray')) {
            return;
        }

        $payload = Locale::using($user->preferredLocale(), function () use ($notification, $user) {
            $data = $notification->toArray($user);
            $title = $data['message'] ?? $data['title'] ?? 'ServerScope';
            $body = isset($data['message'], $data['title']) && $data['title'] !== $data['message'] ? $data['title'] : ($data['body'] ?? '');

            return [
                'title' => mb_strimwidth((string) $title, 0, 120, '…'),
                'body' => mb_strimwidth((string) $body, 0, 200, '…'),
                'url' => url($data['url'] ?? '/dashboard'),
                'tag' => (string) ($data['incident_id'] ?? $data['kind'] ?? 'serverscope'),
            ];
        });

        SendPushNotification::dispatch($user->id, $payload, $notificationId);
    }
}
