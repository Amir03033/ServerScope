@php
    $isEditing = $rule->exists;

    $metricLabels = \App\Models\AlertRule::METRIC_LABELS;

    $operatorLabels = [
        '>=' => 'Groter dan of gelijk aan (≥)',
        '>' => 'Groter dan (>)',
        '<=' => 'Kleiner dan of gelijk aan (≤)',
        '<' => 'Kleiner dan (<)',
    ];
@endphp

<style>
    .form-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 20px;
    }

    .field {
        display: grid;
        gap: 7px;
        min-width: 0;
        align-content: start;
    }

    .field-full { grid-column: 1 / -1; }

    .field > label {
        color: #c3d1e4;
        font-size: 12px;
        font-weight: 600;
    }

    .field input[type="text"],
    .field input[type="number"],
    .field select {
        width: 100%;
        min-height: 44px;
        padding: 10px 12px;
        border: 1px solid #2a3c58;
        border-radius: 10px;
        outline: none;
        background: #080f1c;
        color: #edf4ff;
        font: inherit;
        transition: border-color .15s, box-shadow .15s;
    }

    .field select {
        appearance: none;
        -webkit-appearance: none;
        padding-right: 36px;
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%238397b1' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E");
        background-repeat: no-repeat;
        background-position: right 12px center;
        background-size: 16px;
    }

    .field input:focus,
    .field select:focus {
        border-color: #0891b2;
        box-shadow: 0 0 0 3px #0891b230;
    }

    .field-help { color: #8397b1; font-size: 11px; }
    .field-error { color: #fb7185; font-size: 12px; }

    .checkbox-field {
        display: flex;
        align-items: flex-start;
        gap: 12px;
        padding: 15px;
        border: 1px solid #1f2e47;
        border-radius: 12px;
        background: #0a1322;
    }

    .checkbox-field input {
        width: 18px;
        height: 18px;
        margin-top: 2px;
        accent-color: #38bdf8;
    }

    .checkbox-field label {
        display: grid;
        gap: 4px;
        cursor: pointer;
        font-size: 13px;
    }

    .form-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        margin-top: 24px;
        padding-top: 20px;
        border-top: 1px solid #1a2840;
    }

    @media (max-width: 600px) {
        .form-grid { grid-template-columns: 1fr; }
        .field-full { grid-column: auto; }
    }
</style>

<form
    method="POST"
    action="{{ $isEditing
        ? route('alert-rules.update', $rule)
        : route('alert-rules.store') }}"
>
    @csrf

    @if ($isEditing)
        @method('PUT')
    @endif

    <div class="form-grid">
        <div class="field field-full">
            <label for="name">Naam van de alertregel</label>

            <input
                id="name"
                name="name"
                type="text"
                maxlength="100"
                required
                value="{{ old('name', $rule->name) }}"
                placeholder="Bijv. Hoog CPU-gebruik"
                autocomplete="off"
            >

            <span class="field-help">
                Kies een duidelijke naam waarmee je de melding herkent.
            </span>

            @error('name')
            <span class="field-error">{{ $message }}</span>
            @enderror
        </div>

        <div class="field">
            <label for="metric">Metric</label>

            <select id="metric" name="metric" required>
                @foreach ($metricLabels as $value => $label)
                    <option
                        value="{{ $value }}"
                        @selected(old('metric', $rule->metric ?: 'cpu_percent') === $value)
                    >
                        {{ $label }}
                    </option>
                @endforeach
            </select>

            <span class="field-help">
                Welke systeemwaarde moet worden gecontroleerd?
            </span>

            @error('metric')
            <span class="field-error">{{ $message }}</span>
            @enderror
        </div>

        <div class="field">
            <label for="threshold">Drempelwaarde</label>

            <input
                id="threshold"
                name="threshold"
                type="number"
                min="0"
                max="1440"
                step="0.01"
                required
                value="{{ old('threshold', $rule->threshold ?? 85) }}"
            >

            <span class="field-help">
                CPU, RAM en schijf: een percentage tussen 0 en 100.
                Agent offline: het aantal minuten zonder heartbeat (2–1440);
                voorwaarde en duur worden dan genegeerd.
            </span>

            @error('threshold')
            <span class="field-error">{{ $message }}</span>
            @enderror
        </div>

        <div class="field">
            <label for="operator">Voorwaarde</label>

            <select id="operator" name="operator" required>
                @foreach ($operatorLabels as $value => $label)
                    <option
                        value="{{ $value }}"
                        @selected(old('operator', $rule->operator ?: '>=') === $value)
                    >
                        {{ $label }}
                    </option>
                @endforeach
            </select>

            @error('operator')
            <span class="field-error">{{ $message }}</span>
            @enderror
        </div>

        <div class="field">
            <label for="severity">Ernst van het incident</label>

            <select id="severity" name="severity" required>
                @foreach (\App\Support\Severity::options() as $value => $label)
                    <option value="{{ $value }}" @selected(old('severity', $rule->severity ?? ($rule->metric === 'agent_offline' ? 'critical' : 'warning')) === $value)>{{ $label }}</option>
                @endforeach
            </select>

            <span class="field-help">Bepaalt de ernst van incidenten die deze regel opent.</span>

            @error('severity')
            <span class="field-error">{{ $message }}</span>
            @enderror
        </div>

        <div class="field">
            <label for="duration_minutes">Vereiste duur (minuten)</label>

            <input
                id="duration_minutes"
                name="duration_minutes"
                type="number"
                min="1"
                max="1440"
                step="1"
                required
                value="{{ old('duration_minutes', $rule->duration_minutes ?? 1) }}"
            >

            <span class="field-help">
                De voorwaarde moet gedurende deze periode worden waargenomen.
            </span>

            @error('duration_minutes')
            <span class="field-error">{{ $message }}</span>
            @enderror
        </div>

        <div class="field">
            <label for="recovery_samples">Herstelbevestiging (metingen)</label>

            <input
                id="recovery_samples"
                name="recovery_samples"
                type="number"
                min="1"
                max="30"
                step="1"
                value="{{ old('recovery_samples', $rule->recovery_samples ?? 1) }}"
            >

            <span class="field-help">
                Aantal opeenvolgende goede metingen voordat de alert als hersteld geldt (1 = direct).
                Voorkomt herstelmeldingen bij een kortstondige dip.
            </span>

            @error('recovery_samples')
            <span class="field-error">{{ $message }}</span>
            @enderror
        </div>

        <div class="field field-full">
            <div class="checkbox-field">
                <input
                    id="is_enabled"
                    name="is_enabled"
                    type="checkbox"
                    value="1"
                    @checked((bool) old('is_enabled', $rule->exists ? $rule->is_enabled : true))
                >

                <label for="is_enabled">
                    <strong>Alertregel inschakelen</strong>
                    <span class="field-help">
                        Ingeschakelde regels worden door de alertdetectie geëvalueerd.
                    </span>
                </label>
            </div>

            @error('is_enabled')
            <span class="field-error">{{ $message }}</span>
            @enderror
        </div>
    </div>

    <div class="form-actions">
        <button type="submit" class="button button-primary">
            {{ $isEditing ? 'Wijzigingen opslaan' : 'Alertregel aanmaken' }}
        </button>

        <a href="{{ route('alert-rules.index') }}" class="button">
            Annuleren
        </a>
    </div>
</form>
