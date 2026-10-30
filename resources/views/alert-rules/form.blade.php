@php
    use App\Models\AlertRule;
    use App\Support\Format;
    use App\Support\Label;
    use App\Support\Severity;

    $isEditing = $rule->exists;
    $field = fn (string $name) => $errors->has($name) ? 'aria-invalid=true aria-describedby='.$name.'-error' : '';
    $operatorSymbols = ['>=' => '≥', '>' => '>', '<=' => '≤', '<' => '<'];

    // Server-rendered preview sentence; app.js keeps it up to date while typing.
    $metric = old('metric', $rule->metric ?: 'cpu_percent');
    $threshold = old('threshold', $rule->threshold ?? 85);
    $minutes = old('duration_minutes', $rule->duration_minutes ?? 1);
    $operator = old('operator', $rule->operator ?: '>=');
    $preview = $metric === AlertRule::METRIC_AGENT_OFFLINE
        ? __('alert_rules.form.preview_offline', ['threshold' => Format::number($threshold, fmod((float) $threshold, 1) ? 2 : 0)])
        : __('alert_rules.form.preview', ['metric' => Label::of('alert_metric', $metric, AlertRule::METRIC_LABELS), 'operator' => $operatorSymbols[$operator] ?? $operator, 'threshold' => Format::number($threshold, fmod((float) $threshold, 1) ? 2 : 0).AlertRule::metricUnit($metric), 'minutes' => $minutes]);
@endphp

<form method="POST" action="{{ $isEditing ? route('alert-rules.update', $rule) : route('alert-rules.store') }}"
      data-rule-preview
      data-template="{{ __('alert_rules.form.preview') }}"
      data-template-offline="{{ __('alert_rules.form.preview_offline') }}"
      data-offline-metric="{{ AlertRule::METRIC_AGENT_OFFLINE }}"
      data-units="{{ json_encode(collect(AlertRule::METRIC_LABELS)->mapWithKeys(fn ($l, $m) => [$m => AlertRule::metricUnit($m)])) }}"
      data-operators="{{ json_encode($operatorSymbols) }}">
    @csrf
    @if ($isEditing)
        @method('PUT')
    @endif

    <p class="ss-alert ss-alert-info mt-0" role="status" aria-live="polite">
        <svg class="ico"><use href="#i-bell"/></svg>
        <span><span class="sr-only">{{ __('alert_rules.form.preview_label') }}: </span><span data-preview-text>{{ $preview }}</span></span>
    </p>

    <div class="mt-5 grid gap-5 sm:grid-cols-2">
        <div class="sm:col-span-2">
            <label class="ss-label" for="name">{{ __('alert_rules.form.name') }}</label>
            <input id="name" class="ss-input mt-1.5" name="name" type="text" maxlength="100" required value="{{ old('name', $rule->name) }}" placeholder="{{ __('alert_rules.form.name_placeholder') }}" autocomplete="off" {{ $field('name') }}>
            <p class="ss-help">{{ __('alert_rules.form.name_help') }}</p>
            @error('name')<p id="name-error" class="ss-error">{{ $message }}</p>@enderror
        </div>

        <div>
            <label class="ss-label" for="metric">{{ __('alert_rules.form.metric') }}</label>
            <select id="metric" class="ss-input mt-1.5" name="metric" required {{ $field('metric') }}>
                @foreach (AlertRule::METRIC_LABELS as $value => $label)
                    <option value="{{ $value }}" @selected($metric === $value)>{{ Label::of('alert_metric', $value, AlertRule::METRIC_LABELS) }}</option>
                @endforeach
            </select>
            <p class="ss-help">{{ __('alert_rules.form.metric_help') }}</p>
            @error('metric')<p id="metric-error" class="ss-error">{{ $message }}</p>@enderror
        </div>

        <div>
            <label class="ss-label" for="threshold">{{ __('alert_rules.form.threshold') }}</label>
            <input id="threshold" class="ss-input mt-1.5" name="threshold" type="number" min="0" max="1440" step="0.01" required value="{{ $threshold }}" {{ $field('threshold') }}>
            <p class="ss-help">{{ __('alert_rules.form.threshold_help') }}</p>
            @error('threshold')<p id="threshold-error" class="ss-error">{{ $message }}</p>@enderror
        </div>

        <div>
            <label class="ss-label" for="operator">{{ __('alert_rules.form.operator') }}</label>
            <select id="operator" class="ss-input mt-1.5" name="operator" required {{ $field('operator') }}>
                @foreach (array_keys($operatorSymbols) as $value)
                    <option value="{{ $value }}" @selected($operator === $value)>{{ __('alert_rules.form.operators.'.$value) }}</option>
                @endforeach
            </select>
            @error('operator')<p id="operator-error" class="ss-error">{{ $message }}</p>@enderror
        </div>

        <div>
            <label class="ss-label" for="severity">{{ __('alert_rules.form.severity') }}</label>
            <select id="severity" class="ss-input mt-1.5" name="severity" required {{ $field('severity') }}>
                @foreach (Severity::options() as $value => $label)
                    <option value="{{ $value }}" @selected(old('severity', $rule->severity ?? ($rule->metric === 'agent_offline' ? 'critical' : 'warning')) === $value)>{{ Label::of('severity', $value, Severity::LABELS) }}</option>
                @endforeach
            </select>
            <p class="ss-help">{{ __('alert_rules.form.severity_help') }}</p>
            @error('severity')<p id="severity-error" class="ss-error">{{ $message }}</p>@enderror
        </div>

        <div>
            <label class="ss-label" for="duration_minutes">{{ __('alert_rules.form.duration') }}</label>
            <input id="duration_minutes" class="ss-input mt-1.5" name="duration_minutes" type="number" min="1" max="1440" step="1" required value="{{ $minutes }}" {{ $field('duration_minutes') }}>
            <p class="ss-help">{{ __('alert_rules.form.duration_help') }}</p>
            @error('duration_minutes')<p id="duration_minutes-error" class="ss-error">{{ $message }}</p>@enderror
        </div>

        <div>
            <label class="ss-label" for="recovery_samples">{{ __('alert_rules.form.recovery') }}</label>
            <input id="recovery_samples" class="ss-input mt-1.5" name="recovery_samples" type="number" min="1" max="30" step="1" value="{{ old('recovery_samples', $rule->recovery_samples ?? 1) }}" {{ $field('recovery_samples') }}>
            <p class="ss-help">{{ __('alert_rules.form.recovery_help') }}</p>
            @error('recovery_samples')<p id="recovery_samples-error" class="ss-error">{{ $message }}</p>@enderror
        </div>

        <div class="sm:col-span-2">
            {{-- Unchecked sends 0: the controller requires the field (it could not be switched off before). --}}
            <input type="hidden" name="is_enabled" value="0">
            <label class="ss-check items-start" for="is_enabled">
                <input id="is_enabled" class="mt-0.5" name="is_enabled" type="checkbox" value="1" @checked((bool) old('is_enabled', $rule->exists ? $rule->is_enabled : true))>
                <span>
                    <strong>{{ __('alert_rules.form.enabled') }}</strong>
                    <span class="ss-help block !mt-0.5">{{ __('alert_rules.form.enabled_help') }}</span>
                </span>
            </label>
            @error('is_enabled')<p class="ss-error">{{ $message }}</p>@enderror
        </div>
    </div>

    <div class="mt-6 flex flex-wrap gap-2 border-t border-line pt-5">
        <button type="submit" class="ss-btn ss-btn-primary">{{ $isEditing ? __('alert_rules.form.save') : __('alert_rules.form.submit') }}</button>
        <a href="{{ route('alert-rules.index') }}" class="ss-btn ss-btn-ghost">{{ __('alert_rules.form.cancel') }}</a>
    </div>
</form>
