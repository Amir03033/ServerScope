@php
    use App\Models\Server;
    use App\Support\Time;

    $isEditing = $server->exists;
@endphp

<div class="form-cols">
    <div @class(['form-field', 'has-error' => $errors->has('name')])>
        <label for="name">Servernaam *</label>
        <input id="name" name="name" type="text" maxlength="100" required value="{{ old('name', $server->name) }}" placeholder="Production VPS">
        @error('name')<span class="form-error">{{ $message }}</span>@enderror
    </div>

    <div @class(['form-field', 'has-error' => $errors->has('hostname')])>
        <label for="hostname">Hostname *</label>
        <input id="hostname" name="hostname" type="text" maxlength="255" required value="{{ old('hostname', $server->hostname) }}" placeholder="server.example.com">
        <span class="form-help">DNS-naam of IP-adres waarmee je de server herkent.</span>
        @error('hostname')<span class="form-error">{{ $message }}</span>@enderror
    </div>

    <div @class(['form-field', 'has-error' => $errors->has('ip_address')])>
        <label for="ip_address">IP-adres</label>
        <input id="ip_address" name="ip_address" type="text" maxlength="45" value="{{ old('ip_address', $server->ip_address) }}" placeholder="203.0.113.10">
        @error('ip_address')<span class="form-error">{{ $message }}</span>@enderror
    </div>

    <div @class(['form-field', 'has-error' => $errors->has('operating_system')])>
        <label for="operating_system">Besturingssysteem</label>
        <input id="operating_system" name="operating_system" type="text" maxlength="100" value="{{ old('operating_system', $server->operating_system) }}" placeholder="Ubuntu 24.04">
        @error('operating_system')<span class="form-error">{{ $message }}</span>@enderror
    </div>

    <div @class(['form-field', 'has-error' => $errors->has('environment')])>
        <label for="environment">Omgeving *</label>
        <select id="environment" name="environment" required>
            @foreach (Server::ENVIRONMENTS as $value => $label)
                <option value="{{ $value }}" @selected(old('environment', $server->environment ?? 'production') === $value)>{{ $label }}</option>
            @endforeach
        </select>
        @error('environment')<span class="form-error">{{ $message }}</span>@enderror
    </div>

    @unless ($isEditing)
        <div @class(['form-field', 'has-error' => $errors->has('credential_name')])>
            <label for="credential_name">Naam agentcredential *</label>
            <input id="credential_name" name="credential_name" type="text" maxlength="100" required value="{{ old('credential_name', 'Primary agent') }}">
            <span class="form-help">Na het opslaan wordt het agenttoken eenmalig getoond.</span>
            @error('credential_name')<span class="form-error">{{ $message }}</span>@enderror
        </div>
    @endunless

    <div @class(['form-field full', 'has-error' => $errors->has('notes')])>
        <label for="notes">Notities</label>
        <textarea id="notes" name="notes" maxlength="2000" placeholder="Bijv. hostingprovider, contactpersoon, rol van de server">{{ old('notes', $server->notes) }}</textarea>
        @error('notes')<span class="form-error">{{ $message }}</span>@enderror
    </div>

    @if ($isEditing)
        <div class="form-section">
            <h3>Meldingen en beschikbaarheidsdoel</h3>
            <p>Incidenten worden altijd vastgelegd; met meldingen uit worden er geen e-mails, in-app- of chatmeldingen voor deze server verstuurd.</p>
        </div>

        <div class="form-field">
            <label class="check">
                <input type="hidden" name="notifications_enabled" value="0">
                <input type="checkbox" name="notifications_enabled" value="1" @checked(old('notifications_enabled', $server->notifications_enabled ?? true))>
                Meldingen versturen voor deze server
            </label>
        </div>

        <div @class(['form-field', 'has-error' => $errors->has('sla_target')])>
            <label for="sla_target">Beschikbaarheidsdoel (%)</label>
            <input id="sla_target" name="sla_target" type="text" inputmode="decimal" value="{{ old('sla_target', $server->sla_target) }}" placeholder="{{ config('serverscope.reports.default_target') }} (standaard)">
            @error('sla_target')<span class="form-error">{{ $message }}</span>@enderror
        </div>

        <div class="form-section">
            <h3>Onderhoudsvenster</h3>
            <p>
                Tijdens onderhoud worden geen nieuwe downtime- of resource-alerts aangemaakt. Laat beide velden leeg om
                geen onderhoud te plannen. Tijden in {{ Time::zone() }}.
            </p>
        </div>

        <div @class(['form-field', 'has-error' => $errors->has('maintenance_starts_at')])>
            <label for="maintenance_starts_at">Start</label>
            <input id="maintenance_starts_at" name="maintenance_starts_at" type="datetime-local" value="{{ old('maintenance_starts_at', Time::input($server->maintenance_starts_at)) }}">
            @error('maintenance_starts_at')<span class="form-error">{{ $message }}</span>@enderror
        </div>

        <div @class(['form-field', 'has-error' => $errors->has('maintenance_ends_at')])>
            <label for="maintenance_ends_at">Einde</label>
            <input id="maintenance_ends_at" name="maintenance_ends_at" type="datetime-local" value="{{ old('maintenance_ends_at', Time::input($server->maintenance_ends_at)) }}">
            <span class="form-help">Leeg laten voor onderhoud zonder vaste eindtijd.</span>
            @error('maintenance_ends_at')<span class="form-error">{{ $message }}</span>@enderror
        </div>

        <div @class(['form-field full', 'has-error' => $errors->has('maintenance_reason')])>
            <label for="maintenance_reason">Reden</label>
            <input id="maintenance_reason" name="maintenance_reason" type="text" maxlength="255" value="{{ old('maintenance_reason', $server->maintenance_reason) }}" placeholder="Bijv. kernel-update">
            @error('maintenance_reason')<span class="form-error">{{ $message }}</span>@enderror
        </div>
    @endif
</div>
