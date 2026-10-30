@php
    use App\Models\WebhookEndpoint;
    use App\Support\Label;
    use App\Support\Severity;

    $editing = $endpoint->exists;
    $discord = $endpoint->isDiscord();
    $docs = config('serverscope.docs_url');
    $field = fn (string $name) => $errors->has($name) ? 'aria-invalid=true aria-describedby='.$name.'-error' : '';
@endphp

<x-layouts.app :title="$editing ? __('webhooks.form.title_edit') : ($discord ? __('webhooks.form.title_discord') : __('webhooks.form.title_create'))">
    <x-slot:breadcrumb><span>›</span> <a href="{{ route('webhooks.index') }}">{{ __('webhooks.title') }}</a> <span>›</span> {{ $editing ? $endpoint->name : __('webhooks.new') }}</x-slot:breadcrumb>
    <x-slot:actions><a class="ss-btn ss-btn-ghost" href="{{ $editing ? route('webhooks.show', $endpoint) : route('webhooks.index') }}"><svg class="ico"><use href="#i-arrow-left"/></svg> {{ __('webhooks.back') }}</a></x-slot:actions>

    <section class="ss-panel max-w-4xl">
        <form method="POST" action="{{ $editing ? route('webhooks.update', $endpoint) : route('webhooks.store') }}">
            @csrf
            @if ($editing) @method('PUT') @else <input type="hidden" name="type" value="{{ $endpoint->type }}"> @endif
            <div class="grid gap-5 sm:grid-cols-2">
                <div>
                    <label class="ss-label" for="name">{{ __('webhooks.form.name') }}</label>
                    <input id="name" class="ss-input mt-1.5" name="name" maxlength="100" required value="{{ old('name', $endpoint->name) }}" {{ $field('name') }}>
                    @error('name')<p id="name-error" class="ss-error">{{ $message }}</p>@enderror
                </div>
                @if ($discord)
                    {{-- The Discord URL is a credential: never echoed back into the page. --}}
                    <div>
                        <label class="ss-label" for="url">{{ __('webhooks.form.discord_url') }}</label>
                        <input id="url" class="ss-input mt-1.5" name="url" type="password" maxlength="2048" autocomplete="off" spellcheck="false" @unless ($editing) required @endunless placeholder="{{ $editing ? __('webhooks.form.keep_url') : 'https://discord.com/api/webhooks/…' }}" {{ $field('url') }}>
                        <p class="ss-help">
                            {{ __('webhooks.form.discord_help') }}
                            @if ($editing) {!! __('webhooks.form.current', ['url' => '<code>'.e($endpoint->url).'</code>']) !!} @endif
                            @if ($docs) <a class="text-accent" href="{{ $docs }}/discord-notifications" target="_blank" rel="noopener">{{ __('webhooks.form.guide') }}</a> @endif
                        </p>
                        @error('url')<p id="url-error" class="ss-error">{{ $message }}</p>@enderror
                    </div>
                @else
                    <div>
                        <label class="ss-label" for="url">{{ __('webhooks.form.url') }}</label>
                        <input id="url" class="ss-input mt-1.5" name="url" type="url" maxlength="2048" required value="{{ old('url', $endpoint->url) }}" placeholder="https://hooks.example.com/serverscope" {{ $field('url') }}>
                        <p class="ss-help">{{ __('webhooks.form.url_help') }}</p>
                        @error('url')<p id="url-error" class="ss-error">{{ $message }}</p>@enderror
                    </div>
                @endif
                <div>
                    <label class="ss-label" for="min_severity">{{ __('webhooks.form.min_severity') }}</label>
                    <select id="min_severity" class="ss-input mt-1.5" name="min_severity" {{ $field('min_severity') }}>
                        <option value="">{{ __('webhooks.form.all_incidents') }}</option>
                        @foreach (WebhookEndpoint::SEVERITIES as $value => $label)
                            <option value="{{ $value }}" @selected(old('min_severity', $endpoint->min_severity) === $value)>{{ __('webhooks.form.and_higher', ['severity' => Label::of('severity', $value, Severity::LABELS)]) }}</option>
                        @endforeach
                    </select>
                    <p class="ss-help">{{ __('webhooks.form.min_severity_help') }}</p>
                    @error('min_severity')<p id="min_severity-error" class="ss-error">{{ $message }}</p>@enderror
                </div>
                <fieldset class="m-0 border-0 p-0 sm:col-span-2" @error('events') aria-describedby="events-error" @enderror>
                    <legend class="ss-label">{{ __('webhooks.form.events') }}</legend>
                    <div class="mt-2 grid gap-3 sm:grid-cols-2">
                        @foreach (WebhookEndpoint::EVENTS as $event => $label)
                            <label class="ss-check items-start"><input class="mt-0.5" type="checkbox" name="events[]" value="{{ $event }}" @checked(in_array($event, old('events', $endpoint->events ?? []), true))> <span><code>{{ $event }}</code><span class="ss-help block !mt-0">{{ Label::of('webhook_event', $event, WebhookEndpoint::EVENTS) }}</span></span></label>
                        @endforeach
                    </div>
                    @error('events')<p id="events-error" class="ss-error">{{ $message }}</p>@enderror
                </fieldset>
                <div class="sm:col-span-2">
                    <label class="ss-check"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $endpoint->is_active ?? true))> {{ __('webhooks.form.active') }}</label>
                </div>
            </div>
            <div class="mt-6 border-t border-line pt-5"><button class="ss-btn ss-btn-primary" type="submit">{{ $editing ? __('webhooks.form.save') : ($discord ? __('webhooks.form.create_discord') : __('webhooks.form.create')) }}</button></div>
        </form>
    </section>
</x-layouts.app>
