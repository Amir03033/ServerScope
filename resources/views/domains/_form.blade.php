@php
    use App\Models\Domain;
    use App\Support\Time;

    $isEditing = $domain->exists;
    $days = fn ($value) => implode(', ', Domain::normaliseDays($value));
    $checked = fn (string $key) => (bool) old($key, $isEditing ? $domain->{$key} : true);
    // Old input holds the parsed array after a failed validation.
    $oldDays = fn (string $key) => is_array(old($key)) ? implode(', ', old($key)) : old($key, $days($domain->{$key}));
    $field = fn (string ...$names) => collect($names)->contains(fn ($name) => $errors->has($name)) ? 'aria-invalid=true aria-describedby='.$names[0].'-error' : '';
@endphp

<div class="grid gap-4">
    <section class="ss-panel" aria-labelledby="form-address">
        <h2 id="form-address" class="ss-panel-title mb-4">{{ __('domains.form.address_title') }}</h2>
        <div class="grid gap-5 sm:grid-cols-2">
            <div>
                <label class="ss-label" for="name">{{ __('domains.form.name') }}</label>
                <input id="name" class="ss-input mt-1.5" name="name" type="text" maxlength="253" required value="{{ old('name_input', old('name', $domain->exists ? $domain->displayName() : '')) }}" placeholder="{{ __('domains.form.name_placeholder') }}" autocomplete="off" {{ $field('name') }}>
                <p class="ss-help">{{ __('domains.form.name_help') }}</p>
                @error('name')<p id="name-error" class="ss-error">{{ $message }}</p>@enderror
            </div>

            <div>
                <label class="ss-label" for="scheme">{{ __('domains.form.url') }}</label>
                <div class="mt-1.5 grid grid-cols-[7rem_minmax(0,1fr)] gap-2">
                    <select id="scheme" class="ss-input" name="scheme">
                        <option value="https" @selected(old('scheme', $domain->scheme ?? 'https') === 'https')>https://</option>
                        <option value="http" @selected(old('scheme', $domain->scheme) === 'http')>http://</option>
                    </select>
                    <input id="path" class="ss-input" name="path" type="text" maxlength="255" value="{{ old('path', $domain->path ?? '/') }}" aria-label="{{ __('domains.form.path') }}" placeholder="/" {{ $field('path') }}>
                </div>
                <p class="ss-help">{{ __('domains.form.path_help') }}</p>
                @if ($domain->exists)
                    <p class="ss-help" data-testid="checked-address">{{ __('domains.form.checked_address', ['url' => $domain->scheme.'://'.$domain->name.$domain->path]) }}</p>
                @endif
                @error('path')<p id="path-error" class="ss-error">{{ $message }}</p>@enderror
            </div>

            <div class="sm:col-span-2">
                <label class="ss-label" for="notes">{{ __('domains.form.notes') }}</label>
                <textarea id="notes" class="ss-input mt-1.5 min-h-24" name="notes" maxlength="2000" {{ $field('notes') }}>{{ old('notes', $domain->notes) }}</textarea>
                @error('notes')<p id="notes-error" class="ss-error">{{ $message }}</p>@enderror
            </div>
        </div>
    </section>

    <section class="ss-panel" aria-labelledby="form-checks">
        <h2 id="form-checks" class="ss-panel-title">{{ __('domains.form.checks_title') }}</h2>
        <p class="ss-panel-sub mb-4">{{ __('domains.form.checks_text') }}</p>
        <div class="grid gap-5 sm:grid-cols-2">
            <fieldset class="m-0 grid gap-2.5 border-0 p-0 sm:col-span-2 sm:grid-cols-2">
                <legend class="sr-only">{{ __('domains.form.checks_title') }}</legend>
                @foreach (['http_enabled', 'ssl_enabled', 'dns_enabled', 'expiry_enabled'] as $key)
                    <label class="ss-check">
                        <input type="hidden" name="{{ $key }}" value="0">
                        <input type="checkbox" name="{{ $key }}" value="1" @checked($checked($key))>
                        {{ __('domains.form.checks.'.$key) }}
                    </label>
                @endforeach
            </fieldset>

            <div>
                <label class="ss-label" for="check_interval_minutes">{{ __('domains.form.interval') }}</label>
                <input id="check_interval_minutes" class="ss-input mt-1.5" name="check_interval_minutes" type="number" min="{{ config('serverscope.monitoring.min_interval_minutes', 1) }}" max="1440" required value="{{ old('check_interval_minutes', $domain->check_interval_minutes ?? 5) }}" {{ $field('check_interval_minutes') }}>
                <p class="ss-help">{{ __('domains.form.interval_help', ['dns' => config('serverscope.monitoring.dns_interval_minutes'), 'ssl' => intdiv(config('serverscope.monitoring.ssl_interval_minutes'), 60)]) }}</p>
                @error('check_interval_minutes')<p id="check_interval_minutes-error" class="ss-error">{{ $message }}</p>@enderror
            </div>

            <div>
                <label class="ss-label" for="timeout_seconds">{{ __('domains.form.timeout') }}</label>
                <input id="timeout_seconds" class="ss-input mt-1.5" name="timeout_seconds" type="number" min="2" max="30" required value="{{ old('timeout_seconds', $domain->timeout_seconds ?? 10) }}" {{ $field('timeout_seconds') }}>
                @error('timeout_seconds')<p id="timeout_seconds-error" class="ss-error">{{ $message }}</p>@enderror
            </div>

            <div>
                <label class="ss-label" for="expected_status_codes">{{ __('domains.form.status_codes') }}</label>
                <input id="expected_status_codes" class="ss-input mt-1.5" name="expected_status_codes" type="text" maxlength="100" value="{{ old('expected_status_codes', $domain->expected_status_codes) }}" placeholder="200-399" {{ $field('expected_status_codes') }}>
                <p class="ss-help">{!! __('domains.form.status_codes_help', ['one' => '<code>200</code>', 'many' => '<code>200-299,301</code>']) !!}</p>
                @error('expected_status_codes')<p id="expected_status_codes-error" class="ss-error">{{ $message }}</p>@enderror
            </div>

            <div>
                <label class="ss-label" for="expected_content">{{ __('domains.form.content') }}</label>
                <input id="expected_content" class="ss-input mt-1.5" name="expected_content" type="text" maxlength="255" value="{{ old('expected_content', $domain->expected_content) }}" placeholder="{{ __('domains.form.content_placeholder') }}" {{ $field('expected_content') }}>
                <p class="ss-help">{{ __('domains.form.content_help', ['mb' => number_format(config('serverscope.monitoring.max_response_bytes') / 1048576, 0)]) }}</p>
                @error('expected_content')<p id="expected_content-error" class="ss-error">{{ $message }}</p>@enderror
            </div>

            <div class="sm:col-span-2">
                <label class="ss-check">
                    <input type="hidden" name="follow_redirects" value="0">
                    <input type="checkbox" name="follow_redirects" value="1" @checked($checked('follow_redirects'))>
                    {{ __('domains.form.follow_redirects', ['max' => config('serverscope.monitoring.max_redirects')]) }}
                </label>
            </div>
        </div>
    </section>

    <section class="ss-panel" aria-labelledby="form-thresholds">
        <h2 id="form-thresholds" class="ss-panel-title mb-4">{{ __('domains.form.thresholds_title') }}</h2>
        <div class="grid gap-5 sm:grid-cols-2">
            <div>
                <label class="ss-label" for="failure_threshold">{{ __('domains.form.failure_threshold') }}</label>
                <input id="failure_threshold" class="ss-input mt-1.5" name="failure_threshold" type="number" min="1" max="10" required value="{{ old('failure_threshold', $domain->failure_threshold ?? 3) }}" {{ $field('failure_threshold') }}>
                <p class="ss-help">{{ __('domains.form.failure_threshold_help') }}</p>
                @error('failure_threshold')<p id="failure_threshold-error" class="ss-error">{{ $message }}</p>@enderror
            </div>

            <div>
                <label class="ss-label" for="recovery_threshold">{{ __('domains.form.recovery_threshold') }}</label>
                <input id="recovery_threshold" class="ss-input mt-1.5" name="recovery_threshold" type="number" min="1" max="10" required value="{{ old('recovery_threshold', $domain->recovery_threshold ?? 2) }}" {{ $field('recovery_threshold') }}>
                <p class="ss-help">{{ __('domains.form.recovery_threshold_help') }}</p>
                @error('recovery_threshold')<p id="recovery_threshold-error" class="ss-error">{{ $message }}</p>@enderror
            </div>

            <div>
                <label class="ss-label" for="ssl_warning_days">{{ __('domains.form.ssl_warning_days') }}</label>
                <input id="ssl_warning_days" class="ss-input mt-1.5" name="ssl_warning_days" type="text" value="{{ $oldDays('ssl_warning_days') }}" {{ $field('ssl_warning_days', 'ssl_warning_days.*') }}>
                @if ($errors->has('ssl_warning_days') || $errors->has('ssl_warning_days.*'))
                    <div id="ssl_warning_days-error">
                        @error('ssl_warning_days')<p class="ss-error">{{ $message }}</p>@enderror
                        @error('ssl_warning_days.*')<p class="ss-error">{{ $message }}</p>@enderror
                    </div>
                @endif
            </div>

            <div>
                <label class="ss-label" for="expiry_warning_days">{{ __('domains.form.expiry_warning_days') }}</label>
                <input id="expiry_warning_days" class="ss-input mt-1.5" name="expiry_warning_days" type="text" value="{{ $oldDays('expiry_warning_days') }}" {{ $field('expiry_warning_days', 'expiry_warning_days.*') }}>
                @if ($errors->has('expiry_warning_days') || $errors->has('expiry_warning_days.*'))
                    <div id="expiry_warning_days-error">
                        @error('expiry_warning_days')<p class="ss-error">{{ $message }}</p>@enderror
                        @error('expiry_warning_days.*')<p class="ss-error">{{ $message }}</p>@enderror
                    </div>
                @endif
            </div>
        </div>
    </section>

    <section class="ss-panel" aria-labelledby="form-notifications">
        <h2 id="form-notifications" class="ss-panel-title">{{ __('domains.form.notifications_title') }}</h2>
        <p class="ss-panel-sub mb-4">{{ __('domains.form.notifications_text') }}</p>
        <div class="grid gap-5 sm:grid-cols-2">
            <div class="sm:col-span-2">
                <label class="ss-check">
                    <input type="hidden" name="notifications_enabled" value="0">
                    <input type="checkbox" name="notifications_enabled" value="1" @checked(old('notifications_enabled', $domain->notifications_enabled ?? true))>
                    {{ __('domains.form.notifications') }}
                </label>
            </div>

            <div>
                <label class="ss-label" for="sla_target">{{ __('domains.form.sla') }}</label>
                <input id="sla_target" class="ss-input mt-1.5" name="sla_target" type="text" inputmode="decimal" value="{{ old('sla_target', $domain->sla_target) }}" placeholder="{{ __('domains.form.sla_placeholder', ['value' => config('serverscope.reports.default_target')]) }}" {{ $field('sla_target') }}>
                <p class="ss-help">{{ __('domains.form.sla_help') }}</p>
                @error('sla_target')<p id="sla_target-error" class="ss-error">{{ $message }}</p>@enderror
            </div>
        </div>
    </section>

    <section class="ss-panel" aria-labelledby="form-maintenance">
        <h2 id="form-maintenance" class="ss-panel-title">{{ __('domains.form.maintenance_title') }}</h2>
        <p class="ss-panel-sub mb-4">{{ __('domains.form.maintenance_text', ['zone' => Time::zone()]) }}</p>
        <div class="grid gap-5 sm:grid-cols-2">
            <div>
                <label class="ss-label" for="maintenance_starts_at">{{ __('domains.form.start') }}</label>
                <input id="maintenance_starts_at" class="ss-input mt-1.5" name="maintenance_starts_at" type="datetime-local" value="{{ old('maintenance_starts_at', Time::input($domain->maintenance_starts_at)) }}" {{ $field('maintenance_starts_at') }}>
                @error('maintenance_starts_at')<p id="maintenance_starts_at-error" class="ss-error">{{ $message }}</p>@enderror
            </div>

            <div>
                <label class="ss-label" for="maintenance_ends_at">{{ __('domains.form.end') }}</label>
                <input id="maintenance_ends_at" class="ss-input mt-1.5" name="maintenance_ends_at" type="datetime-local" value="{{ old('maintenance_ends_at', Time::input($domain->maintenance_ends_at)) }}" {{ $field('maintenance_ends_at') }}>
                @error('maintenance_ends_at')<p id="maintenance_ends_at-error" class="ss-error">{{ $message }}</p>@enderror
            </div>

            <div class="sm:col-span-2">
                <label class="ss-label" for="maintenance_reason">{{ __('domains.form.reason') }}</label>
                <input id="maintenance_reason" class="ss-input mt-1.5" name="maintenance_reason" type="text" maxlength="255" value="{{ old('maintenance_reason', $domain->maintenance_reason) }}">
            </div>
        </div>
    </section>
</div>
