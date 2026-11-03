<?php

namespace Tests\Feature;

use App\Jobs\SendPushNotification;
use App\Models\NotificationLog;
use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Minishlink\WebPush\VAPID;
use Tests\TestCase;

/** Web push: an extra channel next to e-mail and in-app; off until the VAPID keys are set. */
class PushNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private array $subscription = ['endpoint' => 'https://push.example.test/send/abc123', 'keys' => ['p256dh' => 'BPublicKeyExample', 'auth' => 'AuthSecret'], 'contentEncoding' => 'aes128gcm', 'device' => 'Safari · iOS'];

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Queue::fake();
        $this->user = User::factory()->create(['is_admin' => true, 'notify_in_app' => true]);
    }

    private function configure(): void
    {
        $keys = VAPID::createVapidKeys();
        config(['serverscope.push.public_key' => $keys['publicKey'], 'serverscope.push.private_key' => $keys['privateKey']]);
    }

    public function test_push_is_off_until_the_keys_are_set(): void
    {
        $this->actingAs($this->user)->postJson(route('push.subscribe'), $this->subscription)->assertNotFound();
        $this->actingAs($this->user)->get(route('profile.edit'))->assertOk()->assertSee(__('push.not_configured'));
    }

    public function test_a_browser_turns_push_on_and_off(): void
    {
        $this->configure();

        $this->actingAs($this->user)->postJson(route('push.subscribe'), $this->subscription)->assertOk()->assertJson(['devices' => 1]);
        $this->actingAs($this->user)->postJson(route('push.subscribe'), $this->subscription)->assertOk()->assertJson(['devices' => 1], 'the same browser once');
        $this->assertSame('Safari · iOS', PushSubscription::query()->first()->device);

        // Only https push services.
        $this->actingAs($this->user)->postJson(route('push.subscribe'), [...$this->subscription, 'endpoint' => 'http://insecure.test/x'])->assertUnprocessable();

        $this->actingAs($this->user)->deleteJson(route('push.unsubscribe'), ['endpoint' => $this->subscription['endpoint']])->assertOk()->assertJson(['devices' => 0]);
        $this->get(route('profile.edit'))->assertSee('data-public-key="'.config('serverscope.push.public_key').'"', false);
    }

    public function test_a_notification_also_goes_to_the_users_browsers(): void
    {
        // Without a browser: nothing extra.
        $this->configure();
        $this->actingAs($this->user)->post(route('profile.notifications.test'))->assertRedirect();
        Queue::assertNotPushed(SendPushNotification::class);

        // With a browser: one push job with a short message and a link, logged as channel "push".
        $this->actingAs($this->user)->postJson(route('push.subscribe'), $this->subscription)->assertOk();
        $this->actingAs($this->user)->post(route('profile.notifications.test'))->assertRedirect();

        Queue::assertPushed(SendPushNotification::class, function (SendPushNotification $job) {
            return $job->userId === $this->user->id && filled($job->payload['title']) && str_starts_with($job->payload['url'], 'http');
        });
        $this->assertTrue(NotificationLog::query()->withoutGlobalScopes()->where('channel', 'push')->where('user_id', $this->user->id)->exists());
    }

    public function test_another_users_browser_is_never_removed(): void
    {
        $this->configure();
        $other = User::factory()->create();
        PushSubscription::query()->create(['user_id' => $other->id, 'endpoint' => 'https://push.example.test/other', 'endpoint_hash' => PushSubscription::hashOf('https://push.example.test/other'), 'public_key' => 'x', 'auth_token' => 'y']);

        $this->actingAs($this->user)->deleteJson(route('push.unsubscribe'), ['endpoint' => 'https://push.example.test/other'])->assertOk();
        $this->assertSame(1, PushSubscription::query()->count());
    }
}
