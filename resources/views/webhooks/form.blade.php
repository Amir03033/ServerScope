@php use App\Models\WebhookEndpoint; $editing = $endpoint->exists; @endphp

<x-layouts.app :title="$editing ? 'Webhook bewerken' : 'Webhook toevoegen'">
    <x-slot:breadcrumb><span>›</span> <a href="{{ route('webhooks.index') }}">Webhooks</a> <span>›</span> {{ $editing ? $endpoint->name : 'Nieuw' }}</x-slot:breadcrumb>
    <x-slot:actions><a class="button" href="{{ $editing ? route('webhooks.show', $endpoint) : route('webhooks.index') }}"><svg class="ico"><use href="#i-arrow-left"/></svg> Terug</a></x-slot:actions>

    <section class="panel">
        <form method="POST" action="{{ $editing ? route('webhooks.update', $endpoint) : route('webhooks.store') }}">
            @csrf
            @if ($editing) @method('PUT') @endif
            <div class="form-cols">
                <div @class(['form-field', 'has-error' => $errors->has('name')])>
                    <label for="name">Naam</label>
                    <input id="name" name="name" maxlength="100" required value="{{ old('name', $endpoint->name) }}">
                    @error('name')<span class="form-error">{{ $message }}</span>@enderror
                </div>
                <div @class(['form-field', 'has-error' => $errors->has('url')])>
                    <label for="url">URL</label>
                    <input id="url" name="url" type="url" maxlength="2048" required value="{{ old('url', $endpoint->url) }}" placeholder="https://hooks.example.com/serverscope">
                    <span class="form-help">Alleen publieke https-adressen op poort 443 (of een toegestane poort). Redirects worden niet gevolgd.</span>
                    @error('url')<span class="form-error">{{ $message }}</span>@enderror
                </div>
                <div class="form-field full">
                    <span class="form-label">Gebeurtenissen</span>
                    <div class="check-group">
                        @foreach (WebhookEndpoint::EVENTS as $event => $label)
                            <label class="check"><input type="checkbox" name="events[]" value="{{ $event }}" @checked(in_array($event, old('events', $endpoint->events ?? []), true))> <span><code>{{ $event }}</code><span class="form-help" style="display:block">{{ $label }}</span></span></label>
                        @endforeach
                    </div>
                    @error('events')<span class="form-error">{{ $message }}</span>@enderror
                </div>
                <div class="form-field full">
                    <label class="check"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $endpoint->is_active ?? true))> Actief</label>
                </div>
            </div>
            <div class="form-footer"><button class="button button-primary" type="submit">{{ $editing ? 'Opslaan' : 'Webhook aanmaken' }}</button></div>
        </form>
    </section>
</x-layouts.app>
