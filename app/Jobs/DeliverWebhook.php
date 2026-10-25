<?php

namespace App\Jobs;

use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Monitoring\Net\SafeHttpClient;
use App\Support\Audit;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Jobs\SyncJob;
use Throwable;

/**
 * Sends one webhook delivery through the SSRF-safe client (destination
 * re-validated on every attempt, redirects not followed). Retries with
 * backoff on network errors, timeouts, 408/429 and 5xx; other 4xx
 * responses fail permanently.
 */
class DeliverWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 6;

    public int $timeout = 60;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900, 3600, 21600];

    public const DISABLE_AFTER_FAILURES = 20;

    public function __construct(public int $deliveryId) {}

    public function handle(SafeHttpClient $http): void
    {
        $delivery = WebhookDelivery::query()->with('endpoint')->find($this->deliveryId);

        if ($delivery === null || $delivery->status === 'success' || $delivery->endpoint === null) {
            return;
        }

        $endpoint = $delivery->endpoint;

        if (! $endpoint->is_active && $delivery->event !== 'test') {
            $delivery->forceFill(['status' => 'failed', 'error' => 'Endpoint is uitgeschakeld.'])->save();

            return;
        }

        $body = json_encode($delivery->payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $timestamp = now()->getTimestamp();

        $result = $http->post($endpoint->url, $body, [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'X-ServerScope-Event' => $delivery->event,
            'X-ServerScope-Delivery' => $delivery->uuid,
            'X-ServerScope-Timestamp' => (string) $timestamp,
            'X-ServerScope-Signature' => WebhookEndpoint::signature($endpoint->secret, $timestamp, $body),
        ], 10.0);

        $delivery->attempts++;
        $delivery->duration_ms = $result->durationMs;
        $delivery->response_status = $result->status;
        $delivery->response_body = $result->body !== '' ? mb_substr($result->body, 0, 1000) : null;

        if (! $result->failed() && $result->status >= 200 && $result->status < 300) {
            $delivery->fill(['status' => 'success', 'error' => null, 'delivered_at' => now()])->save();
            $endpoint->forceFill(['consecutive_failures' => 0, 'last_delivery_at' => now(), 'last_status' => 'success'])->save();

            return;
        }

        $error = $result->failed()
            ? $result->errorMessage
            : "HTTP {$result->status}".($result->status >= 300 && $result->status < 400 ? ' (redirects worden niet gevolgd)' : '');
        $retryable = $result->failed() ? $result->error !== 'blocked' : ($result->status >= 500 || in_array($result->status, [408, 429], true));

        // Without a real queue (sync driver) a retry would block or break
        // the request that triggered the event, so give up immediately.
        $canRetry = $retryable && $this->attempts() < $this->tries && ! $this->job instanceof SyncJob;

        $delivery->fill(['status' => $canRetry ? 'retrying' : 'failed', 'error' => mb_substr((string) $error, 0, 500)])->save();

        if ($canRetry) {
            $this->release($this->backoff[$this->attempts() - 1] ?? 21600);

            return;
        }

        if (! $retryable || $this->job === null || $this->job instanceof SyncJob || $this->attempts() >= $this->tries) {
            $this->recordFailure($endpoint);
        }
    }

    public function failed(Throwable $exception): void
    {
        $delivery = WebhookDelivery::query()->with('endpoint')->find($this->deliveryId);

        if ($delivery && $delivery->status !== 'success') {
            $delivery->forceFill(['status' => 'failed'])->save();

            if ($delivery->endpoint) {
                $this->recordFailure($delivery->endpoint);
            }
        }
    }

    private function recordFailure(WebhookEndpoint $endpoint): void
    {
        $endpoint->forceFill([
            'consecutive_failures' => $endpoint->consecutive_failures + 1,
            'last_delivery_at' => now(),
            'last_status' => 'failed',
        ])->save();

        if ($endpoint->is_active && $endpoint->consecutive_failures >= self::DISABLE_AFTER_FAILURES) {
            $endpoint->forceFill(['is_active' => false])->save();
            Audit::log('webhook.auto_disabled', "Webhook '{$endpoint->name}' uitgeschakeld na ".self::DISABLE_AFTER_FAILURES.' mislukte leveringen', $endpoint);
        }
    }
}
