@php
    use App\Models\Server;
    use App\Support\Label;
    use App\Support\Time;

    $isEditing = $server->exists;
    $field = fn (string $name) => $errors->has($name) ? 'aria-invalid=true aria-describedby='.$name.'-error' : '';
@endphp

<div class="grid gap-5 sm:grid-cols-2">
    <div>
        <label class="ss-label" for="name">{{ __('servers.form.name') }}</label>
        <input id="name" class="ss-input mt-1.5" name="name" type="text" maxlength="100" required value="{{ old('name', $server->name) }}" placeholder="{{ __('servers.form.name_placeholder') }}" {{ $field('name') }}>
        @error('name')<p id="name-error" class="ss-error">{{ $message }}</p>@enderror
    </div>

    <div>
        <label class="ss-label" for="hostname">{{ __('servers.form.hostname') }}</label>
        <input id="hostname" class="ss-input mt-1.5" name="hostname" type="text" maxlength="255" required value="{{ old('hostname', $server->hostname) }}" placeholder="{{ __('servers.form.hostname_placeholder') }}" {{ $field('hostname') }}>
        <p class="ss-help">{{ __('servers.form.hostname_help') }}</p>
        @error('hostname')<p id="hostname-error" class="ss-error">{{ $message }}</p>@enderror
    </div>

    <div>
        <label class="ss-label" for="ip_address">{{ __('servers.form.ip') }}</label>
        <input id="ip_address" class="ss-input mt-1.5" name="ip_address" type="text" maxlength="45" value="{{ old('ip_address', $server->ip_address) }}" placeholder="{{ __('servers.form.ip_placeholder') }}" {{ $field('ip_address') }}>
        @error('ip_address')<p id="ip_address-error" class="ss-error">{{ $message }}</p>@enderror
    </div>

    <div>
        <label class="ss-label" for="operating_system">{{ __('servers.form.os') }}</label>
        <input id="operating_system" class="ss-input mt-1.5" name="operating_system" type="text" maxlength="100" value="{{ old('operating_system', $server->operating_system) }}" placeholder="{{ __('servers.form.os_placeholder') }}" {{ $field('operating_system') }}>
        @error('operating_system')<p id="operating_system-error" class="ss-error">{{ $message }}</p>@enderror
    </div>

    <div>
        <label class="ss-label" for="environment">{{ __('servers.form.environment') }}</label>
        <select id="environment" class="ss-input mt-1.5" name="environment" required {{ $field('environment') }}>
            @foreach (Server::ENVIRONMENTS as $value => $label)
                <option value="{{ $value }}" @selected(old('environment', $server->environment ?? 'production') === $value)>{{ Label::of('server_environment', $value, Server::ENVIRONMENTS) }}</option>
            @endforeach
        </select>
        @error('environment')<p id="environment-error" class="ss-error">{{ $message }}</p>@enderror
    </div>

    @unless ($isEditing)
        <div>
            <label class="ss-label" for="credential_name">{{ __('servers.form.credential') }}</label>
            <input id="credential_name" class="ss-input mt-1.5" name="credential_name" type="text" maxlength="100" required value="{{ old('credential_name', 'Primary agent') }}" {{ $field('credential_name') }}>
            <p class="ss-help">{{ __('servers.form.credential_help') }}</p>
            @error('credential_name')<p id="credential_name-error" class="ss-error">{{ $message }}</p>@enderror
        </div>
    @endunless

    <div class="sm:col-span-2">
        <label class="ss-label" for="notes">{{ __('servers.form.notes') }}</label>
        <textarea id="notes" class="ss-input mt-1.5 min-h-24" name="notes" maxlength="2000" placeholder="{{ __('servers.form.notes_placeholder') }}" {{ $field('notes') }}>{{ old('notes', $server->notes) }}</textarea>
        @error('notes')<p id="notes-error" class="ss-error">{{ $message }}</p>@enderror
    </div>

    @if ($isEditing)
        <div class="border-t border-line pt-5 sm:col-span-2">
            <h3 class="m-0 text-sm font-semibold">{{ __('servers.form.notifications_title') }}</h3>
            <p class="ss-panel-sub">{{ __('servers.form.notifications_text') }}</p>
        </div>

        <div class="sm:col-span-2">
            <label class="ss-check">
                <input type="hidden" name="notifications_enabled" value="0">
                <input type="checkbox" name="notifications_enabled" value="1" @checked(old('notifications_enabled', $server->notifications_enabled ?? true))>
                {{ __('servers.form.notifications') }}
            </label>
        </div>

        <div>
            <label class="ss-label" for="sla_target">{{ __('servers.form.sla') }}</label>
            <input id="sla_target" class="ss-input mt-1.5" name="sla_target" type="text" inputmode="decimal" value="{{ old('sla_target', $server->sla_target) }}" placeholder="{{ __('servers.form.sla_placeholder', ['value' => config('serverscope.reports.default_target')]) }}" {{ $field('sla_target') }}>
            @error('sla_target')<p id="sla_target-error" class="ss-error">{{ $message }}</p>@enderror
        </div>

        <div>
            <label class="ss-label" for="anomaly_sensitivity">{{ __('servers.form.sensitivity') }}</label>
            <select id="anomaly_sensitivity" class="ss-input mt-1.5" name="anomaly_sensitivity">
                @foreach (['off', 'low', 'medium', 'high'] as $v)
                    <option value="{{ $v }}" @selected(old('anomaly_sensitivity', $server->anomaly_sensitivity ?? 'medium') === $v)>{{ __('servers.form.sensitivities.'.$v) }}</option>
                @endforeach
            </select>
        </div>

        <div class="sm:col-span-2">
            <label class="ss-check">
                <input type="hidden" name="predictive_alerts_enabled" value="0">
                <input type="checkbox" name="predictive_alerts_enabled" value="1" @checked(old('predictive_alerts_enabled', $server->predictive_alerts_enabled ?? true))>
                {{ __('servers.form.predictive', ['days' => config('serverscope.predictive.alert_horizon_days')]) }}
            </label>
        </div>

        <div class="border-t border-line pt-5 sm:col-span-2">
            <h3 class="m-0 text-sm font-semibold">{{ __('servers.form.maintenance_title') }}</h3>
            <p class="ss-panel-sub">{{ __('servers.form.maintenance_text', ['zone' => Time::zone()]) }}</p>
        </div>

        <div>
            <label class="ss-label" for="maintenance_starts_at">{{ __('servers.form.start') }}</label>
            <input id="maintenance_starts_at" class="ss-input mt-1.5" name="maintenance_starts_at" type="datetime-local" value="{{ old('maintenance_starts_at', Time::input($server->maintenance_starts_at)) }}" {{ $field('maintenance_starts_at') }}>
            @error('maintenance_starts_at')<p id="maintenance_starts_at-error" class="ss-error">{{ $message }}</p>@enderror
        </div>

        <div>
            <label class="ss-label" for="maintenance_ends_at">{{ __('servers.form.end') }}</label>
            <input id="maintenance_ends_at" class="ss-input mt-1.5" name="maintenance_ends_at" type="datetime-local" value="{{ old('maintenance_ends_at', Time::input($server->maintenance_ends_at)) }}" {{ $field('maintenance_ends_at') }}>
            <p class="ss-help">{{ __('servers.form.end_help') }}</p>
            @error('maintenance_ends_at')<p id="maintenance_ends_at-error" class="ss-error">{{ $message }}</p>@enderror
        </div>

        <div class="sm:col-span-2">
            <label class="ss-label" for="maintenance_reason">{{ __('servers.form.reason') }}</label>
            <input id="maintenance_reason" class="ss-input mt-1.5" name="maintenance_reason" type="text" maxlength="255" value="{{ old('maintenance_reason', $server->maintenance_reason) }}" placeholder="{{ __('servers.form.reason_placeholder') }}" {{ $field('maintenance_reason') }}>
            @error('maintenance_reason')<p id="maintenance_reason-error" class="ss-error">{{ $message }}</p>@enderror
        </div>
    @endif
</div>
