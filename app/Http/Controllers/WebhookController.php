<?php

namespace App\Http\Controllers;

use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Monitoring\Net\BlockedTargetException;
use App\Monitoring\Net\TargetGuard;
use App\Services\Notifications\DiscordMessage;
use App\Services\Notifications\WebhookDispatcher;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class WebhookController extends Controller
{
    public function index(): View
    {
        Gate::authorize('admin');

        return view('webhooks.index', [
            'endpoints' => WebhookEndpoint::query()->withCount('deliveries')->orderBy('name')->get(),
        ]);
    }

    public function create(): View
    {
        Gate::authorize('admin');

        $type = request()->query('type') === WebhookEndpoint::TYPE_DISCORD ? WebhookEndpoint::TYPE_DISCORD : WebhookEndpoint::TYPE_GENERIC;

        return view('webhooks.form', ['endpoint' => new WebhookEndpoint(['type' => $type, 'events' => array_keys(WebhookEndpoint::EVENTS), 'is_active' => true])]);
    }

    public function store(Request $request, TargetGuard $guard): RedirectResponse
    {
        Gate::authorize('admin');

        $type = $request->input('type') === WebhookEndpoint::TYPE_DISCORD ? WebhookEndpoint::TYPE_DISCORD : WebhookEndpoint::TYPE_GENERIC;
        $data = $this->validated($request, $guard, $type, null);

        if ($type === WebhookEndpoint::TYPE_DISCORD) {
            // The Discord URL is the credential: keep it encrypted, show a mask.
            $endpoint = WebhookEndpoint::query()->create([...$data, 'type' => $type, 'created_by' => $request->user()->id]);
            Audit::log('webhook.created', "Discord-webhook '{$endpoint->name}' aangemaakt", $endpoint, ['type' => $type, 'url_host' => parse_url($endpoint->url, PHP_URL_HOST)]);

            return redirect()->route('webhooks.show', $endpoint)->with('success', __('webhooks.flash.discord_created'));
        }

        $secret = WebhookEndpoint::generateSecret();

        $endpoint = WebhookEndpoint::query()->create([...$data, 'type' => $type, 'secret' => $secret, 'created_by' => $request->user()->id]);
        Audit::log('webhook.created', "Webhook '{$endpoint->name}' aangemaakt", $endpoint, ['url_host' => parse_url($endpoint->url, PHP_URL_HOST)]);

        return redirect()->route('webhooks.show', $endpoint)->with('success', __('webhooks.flash.created'))->with('webhook_secret', $secret);
    }

    public function show(WebhookEndpoint $webhook): View
    {
        Gate::authorize('admin');

        return view('webhooks.show', [
            'endpoint' => $webhook,
            'deliveries' => $webhook->deliveries()->latest('id')->paginate(25),
        ]);
    }

    public function edit(WebhookEndpoint $webhook): View
    {
        Gate::authorize('admin');

        return view('webhooks.form', ['endpoint' => $webhook]);
    }

    public function update(Request $request, WebhookEndpoint $webhook, TargetGuard $guard): RedirectResponse
    {
        Gate::authorize('admin');

        // The type is fixed after creation.
        $webhook->fill($this->validated($request, $guard, $webhook->type, $webhook));
        $urlReplaced = $webhook->isDiscord() && $webhook->isDirty('secret');

        if (($webhook->isDirty('is_active') && $webhook->is_active) || $urlReplaced) {
            $webhook->consecutive_failures = 0;
        }

        $webhook->save();
        Audit::log($urlReplaced ? 'webhook.secret_rotated' : 'webhook.updated', $urlReplaced ? "Discord-URL van webhook '{$webhook->name}' vervangen" : "Webhook '{$webhook->name}' bijgewerkt", $webhook);

        return redirect()->route('webhooks.show', $webhook)->with('success', __('webhooks.flash.saved'));
    }

    public function destroy(WebhookEndpoint $webhook): RedirectResponse
    {
        Gate::authorize('admin');

        $webhook->delete();
        Audit::log('webhook.deleted', "Webhook '{$webhook->name}' verwijderd");

        return redirect()->route('webhooks.index')->with('success', __('webhooks.flash.deleted'));
    }

    public function rotateSecret(WebhookEndpoint $webhook): RedirectResponse
    {
        Gate::authorize('admin');

        // A Discord URL is replaced through the edit form, not generated here.
        abort_if($webhook->isDiscord(), 404);

        $secret = WebhookEndpoint::generateSecret();
        $webhook->forceFill(['secret' => $secret])->save();
        Audit::log('webhook.secret_rotated', "Geheim van webhook '{$webhook->name}' vernieuwd", $webhook);

        return back()->with('success', __('webhooks.flash.rotated'))->with('webhook_secret', $secret);
    }

    public function test(WebhookEndpoint $webhook, WebhookDispatcher $dispatcher): RedirectResponse
    {
        Gate::authorize('admin');

        $key = 'webhook-test:'.$webhook->id;

        if (RateLimiter::tooManyAttempts($key, 5)) {
            return back()->with('error', __('webhooks.flash.test_throttled'));
        }

        RateLimiter::hit($key, 60);
        $dispatcher->test($webhook);

        return back()->with('success', __('webhooks.flash.test_queued'));
    }

    public function redeliver(WebhookEndpoint $webhook, WebhookDelivery $delivery, WebhookDispatcher $dispatcher): RedirectResponse
    {
        Gate::authorize('admin');
        abort_unless($delivery->webhook_endpoint_id === $webhook->id, 404);

        $dispatcher->redeliver($delivery);

        return back()->with('success', __('webhooks.flash.redelivered'));
    }

    private function validated(Request $request, TargetGuard $guard, string $type, ?WebhookEndpoint $existing): array
    {
        $discord = $type === WebhookEndpoint::TYPE_DISCORD;

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            // Editing a Discord webhook: an empty URL keeps the stored one.
            'url' => [$discord && $existing ? 'nullable' : 'required', 'string', 'max:2048', 'url:https,http'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => [Rule::in(array_keys(WebhookEndpoint::EVENTS))],
            'min_severity' => ['nullable', Rule::in(array_keys(WebhookEndpoint::SEVERITIES))],
            'is_active' => ['boolean'],
        ]);

        $data['is_active'] = $request->boolean('is_active');
        $data['events'] = array_values(array_unique($data['events']));
        $data['min_severity'] = $data['min_severity'] ?? null;

        if ($discord) {
            $url = DiscordMessage::normalizeUrl((string) ($data['url'] ?? ''));
            unset($data['url']);

            if ($url === '') {
                return $data;
            }

            // Only real Discord webhook endpoints; the message never
            // repeats the submitted value.
            if (! DiscordMessage::isValidUrl($url)) {
                throw ValidationException::withMessages(['url' => __('webhooks.flash.discord_url')]);
            }

            $this->guard($guard, $url);

            return [...$data, 'secret' => $url, 'url' => DiscordMessage::mask($url)];
        }

        if (str_starts_with(strtolower($data['url']), 'http://') && ! config('serverscope.webhooks.allow_http')) {
            throw ValidationException::withMessages(['url' => __('webhooks.flash.https')]);
        }

        $this->guard($guard, $data['url']);

        return $data;
    }

    /**
     * Same destination rules as monitoring: public addresses and allowed
     * ports only. Re-checked on every delivery.
     */
    private function guard(TargetGuard $guard, string $url): void
    {
        try {
            $guard->resolve($url);
        } catch (BlockedTargetException $exception) {
            throw ValidationException::withMessages(['url' => __('webhooks.flash.not_allowed', ['reason' => DiscordMessage::redact($exception->getMessage())])]);
        }
    }
}
