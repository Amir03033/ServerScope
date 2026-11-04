@php
    use App\Models\Heartbeat;
    use App\Support\Label;
    use App\Support\Severity;

    $editing = $heartbeat->exists;
    $type = old('schedule_type', $heartbeat->schedule_type);
    $timezone = old('timezone', $heartbeat->timezone);
    $field = fn (string $name) => $errors->has($name) ? 'aria-invalid=true aria-describedby='.$name.'-error' : '';
@endphp

<x-layouts.app :title="$editing ? __('heartbeats.form.title_edit') : __('heartbeats.form.title_create')">
    <x-slot:breadcrumb><span>›</span> <a href="{{ route('heartbeats.index') }}">{{ __('heartbeats.title') }}</a> <span>›</span> {{ $editing ? $heartbeat->name : __('heartbeats.new') }}</x-slot:breadcrumb>
    <x-slot:pageTitle>{{ $editing ? __('heartbeats.form.title_edit') : __('heartbeats.form.title_create') }}</x-slot:pageTitle>

    <form method="POST" action="{{ $editing ? route('heartbeats.update', $heartbeat) : route('heartbeats.store') }}" class="ss-panel ss-hb-form max-w-3xl">
        @csrf
        @if ($editing) @method('PUT') @endif

        <div class="grid gap-5">
            <div class="grid gap-5 sm:grid-cols-2">
                <div>
                    <label class="ss-label" for="name">{{ __('heartbeats.form.name') }}</label>
                    <input id="name" class="ss-input mt-1.5" name="name" maxlength="100" required value="{{ old('name', $heartbeat->name) }}" placeholder="{{ __('heartbeats.form.name_placeholder') }}" {{ $field('name') }}>
                    @error('name')<p id="name-error" class="ss-error">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="ss-label" for="server_id">{{ __('heartbeats.form.server') }}</label>
                    <select id="server_id" class="ss-input mt-1.5" name="server_id" {{ $field('server_id') }}>
                        <option value="">{{ __('heartbeats.form.server_none') }}</option>
                        @foreach ($servers as $server)<option value="{{ $server->id }}" @selected((int) old('server_id', $heartbeat->server_id) === $server->id)>{{ $server->name }}</option>@endforeach
                    </select>
                    <p class="ss-help">{{ __('heartbeats.form.server_help') }}</p>
                    @error('server_id')<p id="server_id-error" class="ss-error">{{ $message }}</p>@enderror
                </div>
            </div>

            <fieldset class="m-0 grid gap-2 border-0 p-0">
                <legend class="ss-label mb-1.5">{{ __('heartbeats.form.schedule') }}</legend>
                <div class="grid gap-2 sm:grid-cols-2">
                    @foreach ([Heartbeat::SCHEDULE_INTERVAL => [__('heartbeats.form.type_interval'), __('heartbeats.form.type_interval_text')], Heartbeat::SCHEDULE_CRON => [__('heartbeats.form.type_cron'), __('heartbeats.form.type_cron_text')]] as $value => [$title, $text])
                        <label class="ss-choice">
                            <input type="radio" name="schedule_type" value="{{ $value }}" @checked($type === $value)>
                            <span><strong>{{ $title }}</strong><span>{{ $text }}</span></span>
                        </label>
                    @endforeach
                </div>
            </fieldset>

            <div class="grid gap-5 sm:grid-cols-2" data-schedule="interval">
                <div>
                    <label class="ss-label" for="period_minutes">{{ __('heartbeats.form.period') }}</label>
                    <select id="period_minutes" class="ss-input mt-1.5" name="period_minutes" {{ $field('period_minutes') }}>
                        @foreach (Heartbeat::PERIODS as $minutes)<option value="{{ $minutes }}" @selected((int) old('period_minutes', $heartbeat->period_minutes ?? 60) === $minutes)>{{ mb_strtolower(Heartbeat::periodLabel($minutes)) }}</option>@endforeach
                    </select>
                    @error('period_minutes')<p id="period_minutes-error" class="ss-error">{{ $message }}</p>@enderror
                </div>
            </div>

            <div class="grid gap-5 sm:grid-cols-2" data-schedule="cron">
                <div>
                    <label class="ss-label" for="cron_expression">{{ __('heartbeats.form.cron') }}</label>
                    <input id="cron_expression" class="ss-input mt-1.5 font-mono" name="cron_expression" maxlength="100" value="{{ old('cron_expression', $heartbeat->cron_expression) }}" placeholder="0 3 * * *" autocomplete="off" spellcheck="false" {{ $field('cron_expression') }}>
                    <p class="ss-help">{{ __('heartbeats.form.cron_help') }}</p>
                    @error('cron_expression')<p id="cron_expression-error" class="ss-error">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="ss-label" for="timezone">{{ __('heartbeats.form.timezone') }}</label>
                    <select id="timezone" class="ss-input mt-1.5" name="timezone" {{ $field('timezone') }}>
                        @foreach (DateTimeZone::listIdentifiers() as $zone)<option value="{{ $zone }}" @selected($timezone === $zone)>{{ $zone }}</option>@endforeach
                    </select>
                    @error('timezone')<p id="timezone-error" class="ss-error">{{ $message }}</p>@enderror
                </div>
            </div>

            <div class="grid gap-5 border-t border-line pt-5 sm:grid-cols-2">
                <div>
                    <label class="ss-label" for="grace_minutes">{{ __('heartbeats.form.grace') }}</label>
                    <div class="ss-input-unit mt-1.5">
                        <input id="grace_minutes" class="ss-input" type="number" inputmode="numeric" name="grace_minutes" min="1" max="1440" required value="{{ old('grace_minutes', $heartbeat->grace_minutes) }}" {{ $field('grace_minutes') }}>
                        <span>{{ __('heartbeats.form.minutes') }}</span>
                    </div>
                    <p class="ss-help">{{ __('heartbeats.form.grace_help') }}</p>
                    @error('grace_minutes')<p id="grace_minutes-error" class="ss-error">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="ss-label" for="severity">{{ __('heartbeats.form.severity') }}</label>
                    <select id="severity" class="ss-input mt-1.5" name="severity" {{ $field('severity') }}>
                        @foreach (Severity::options() as $value => $label)<option value="{{ $value }}" @selected(old('severity', $heartbeat->severity) === $value)>{{ Label::of('severity', $value, Severity::LABELS) }}</option>@endforeach
                    </select>
                    @error('severity')<p id="severity-error" class="ss-error">{{ $message }}</p>@enderror
                </div>
            </div>
        </div>

        <div class="mt-6 flex flex-wrap justify-end gap-2 border-t border-line pt-5">
            <a class="ss-btn ss-btn-secondary" href="{{ $editing ? route('heartbeats.show', $heartbeat) : route('heartbeats.index') }}">{{ __('heartbeats.form.cancel') }}</a>
            <button class="ss-btn ss-btn-primary" type="submit">{{ $editing ? __('heartbeats.form.save') : __('heartbeats.form.create') }}</button>
        </div>
    </form>
</x-layouts.app>
