<x-layouts.app :title="'Alertregels'">
    <x-slot:styles>
        .actions form { margin: 0; }
        .button-danger { border-color: color-mix(in srgb, var(--ss-bad) 35%, var(--ss-line)); background: color-mix(in srgb, var(--ss-bad) 8%, var(--ss-surface)); color: var(--red); }
        .button-danger:hover { background: color-mix(in srgb, var(--ss-bad) 8%, var(--ss-surface)); }
        .button-sm { min-height: 36px; padding: 0 12px; font-size: 13px; }
        .notice {
            display: flex; align-items: center; gap: 10px;
            margin-bottom: 16px; padding: 12px 15px;
            border: 1px solid color-mix(in srgb, var(--ss-ok) 35%, var(--ss-line)); border-radius: 10px;
            background: color-mix(in srgb, var(--ss-ok) 8%, var(--ss-surface)); color: var(--green);
        }
        .error-notice { border-color: color-mix(in srgb, var(--ss-bad) 35%, var(--ss-line)); background: color-mix(in srgb, var(--ss-bad) 8%, var(--ss-surface)); color: var(--red); }
        .stats { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 14px; }
        .stat {
            min-width: 0; padding: 18px;
            border: 1px solid var(--border); border-radius: var(--radius);
            background: var(--ss-surface);
        }
        .tone-muted { --tone: var(--ss-muted); }
        .stat-body { display: flex; align-items: center; gap: 14px; }
        .stat-icon {
            display: grid; place-items: center;
            width: 46px; height: 46px; flex: 0 0 46px;
            border: 1px solid color-mix(in srgb, var(--tone) 45%, transparent);
            border-radius: 12px;
            background: color-mix(in srgb, var(--tone) 14%, var(--ss-surface));
            color: var(--tone); font-size: 23px;
        }
        .stat-label { color: var(--ss-muted); font-size: 12px; }
        .stat-value { margin-top: 1px; font-size: 28px; font-weight: 700; letter-spacing: -.6px; line-height: 1.15; color: var(--tone); }
        .panel { margin-top: 14px; padding: 18px; border: 1px solid var(--border); border-radius: var(--radius); background: var(--panel); }
        .section-heading { display: flex; align-items: flex-end; justify-content: space-between; gap: 16px; margin: 0 0 16px; }
        .section-heading h2 { margin: 0; font-size: 16px; }
        .section-heading p { margin: 3px 0 0; color: var(--muted); font-size: 12px; }
        .rule-list { display: grid; gap: 12px; }
        .rule-card {
            --accent: var(--ss-subtle);
            position: relative; min-width: 0; overflow: hidden;
            padding: 18px 18px 18px 20px;
            border: 1px solid var(--border); border-radius: 12px; background: var(--ss-surface-2);
        }
        .rule-card::before { content: ""; position: absolute; inset: 0 auto 0 0; width: 3px; background: var(--accent); }
        .rule-card.is-enabled { --accent: var(--cyan); }
        .rule-top { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; }
        .rule-main { display: flex; align-items: flex-start; gap: 13px; min-width: 0; }
        .rule-icon {
            display: grid; place-items: center;
            width: 38px; height: 38px; flex: 0 0 38px;
            border: 1px solid color-mix(in srgb, var(--accent) 45%, transparent);
            border-radius: 11px;
            background: color-mix(in srgb, var(--accent) 14%, var(--ss-surface));
            color: var(--accent); font-size: 19px;
        }
        .rule-name { margin: 0; font-size: 15px; font-weight: 600; overflow-wrap: anywhere; }
        .rule-description { margin-top: 3px; color: var(--muted); font-size: 12px; }
        .badge {
            display: inline-flex; align-items: center; gap: 7px; flex-shrink: 0;
            padding: 5px 10px;
            border: 1px solid var(--ss-line-strong); border-radius: 99px;
            font-size: 11px; font-weight: 700; white-space: nowrap;
        }
        .badge::before { width: 6px; height: 6px; border-radius: 50%; background: currentColor; content: ""; }
        .badge-enabled { border-color: color-mix(in srgb, var(--ss-ok) 35%, var(--ss-line)); background: color-mix(in srgb, var(--ss-ok) 8%, var(--ss-surface)); color: var(--green); }
        .badge-disabled { background: var(--ss-surface-2); color: var(--muted); }
        .rule-details {
            display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px;
            margin-top: 16px; padding-top: 14px; border-top: 1px solid var(--ss-line);
        }
        .detail-label { margin-bottom: 4px; color: var(--muted); font-size: 11px; }
        .detail-value { font-size: 15px; font-weight: 600; overflow-wrap: anywhere; font-variant-numeric: tabular-nums; }
        .rule-actions { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 16px; }
        .rule-actions form { margin: 0; }
        .empty { padding: 48px 20px; border: 1px dashed var(--ss-line-strong); border-radius: 12px; text-align: center; }
        .empty-icon {
            display: grid; place-items: center; width: 52px; height: 52px; margin: 0 auto 14px;
            border: 1px solid var(--border); border-radius: 14px; background: var(--ss-surface-2); color: var(--cyan); font-size: 24px;
        }
        .empty h3 { margin: 0; font-size: 15px; }
        .empty p { max-width: 400px; margin: 8px auto 18px; color: var(--muted); font-size: 12px; }
        .pagination { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; margin-top: 18px; color: var(--muted); font-size: 12px; }
        .pagination-links { display: flex; gap: 8px; }
        .pagination-links a, .pagination-links span {
            display: inline-flex; align-items: center; justify-content: center;
            min-height: 36px; padding: 0 12px;
            border: 1px solid var(--ss-line-strong); border-radius: 9px; background: var(--ss-surface-2); text-decoration: none;
        }
        .pagination-links a:hover { border-color: var(--ss-subtle); color: var(--cyan); }
        .pagination-links span { color: var(--ss-subtle); cursor: not-allowed; }
        @media (max-width: 600px) {
    .stats { grid-template-columns: 1fr; }
    .rule-details { grid-template-columns: 1fr; }
    .rule-top { flex-direction: column; }
}
    </x-slot:styles>

    <x-slot:breadcrumb>
        <span>›</span> Alertregels
    </x-slot:breadcrumb>

    <x-slot:pageTitle>Alertregels</x-slot:pageTitle>
    <x-slot:subtitle>Configureer de drempelwaarden waarmee ServerScope incidenten detecteert.</x-slot:subtitle>

    <x-slot:actions>

        <a href="{{ route('dashboard') }}" class="button">
            <svg class="ico"><use href="#i-arrow-left"/></svg> Dashboard
        </a>
        <a href="{{ route('alert-rules.create') }}" class="button button-primary">
            <svg class="ico"><use href="#i-plus"/></svg> Nieuwe regel
        </a>
    </x-slot:actions>



    <section class="stats" aria-label="Statistieken alertregels">
        <article class="stat tone-cyan">
            <div class="stat-body">
                <span class="stat-icon"><svg class="ico"><use href="#i-list"/></svg></span>
                <div>
                    <div class="stat-label">Totaal aantal regels</div>
                    <div class="stat-value">{{ number_format($rules->total()) }}</div>
                </div>
            </div>
        </article>

        <article class="stat tone-green">
            <div class="stat-body">
                <span class="stat-icon"><svg class="ico"><use href="#i-check"/></svg></span>
                <div>
                    <div class="stat-label">Ingeschakeld op deze pagina</div>
                    <div class="stat-value">
                        {{ number_format($rules->getCollection()->where('is_enabled', true)->count()) }}
                    </div>
                </div>
            </div>
        </article>

        <article class="stat tone-muted">
            <div class="stat-body">
                <span class="stat-icon"><svg class="ico"><use href="#i-pause"/></svg></span>
                <div>
                    <div class="stat-label">Uitgeschakeld op deze pagina</div>
                    <div class="stat-value">
                        {{ number_format($rules->getCollection()->where('is_enabled', false)->count()) }}
                    </div>
                </div>
            </div>
        </article>
    </section>

    <section class="panel">
        <div class="section-heading">
            <div>
                <h2>Configuratie</h2>
                <p>Beheer de metrics, drempelwaarden en evaluatieduur.</p>
            </div>
            <span class="muted small">{{ $rules->total() }} regels</span>
        </div>

        @if ($rules->isEmpty())
            <div class="empty">
                <div class="empty-icon"><svg class="ico"><use href="#i-sliders"/></svg></div>
                <h3>Er zijn nog geen alertregels</h3>
                <p>Maak je eerste regel aan om CPU-, RAM- of schijfgebruik te bewaken.</p>
                <a href="{{ route('alert-rules.create') }}" class="button button-primary">
                    <svg class="ico"><use href="#i-plus"/></svg> Alertregel aanmaken
                </a>
            </div>
        @else
            <div class="rule-list">
                @foreach ($rules as $rule)
                    @php
                        $metricIcons = [
                            'cpu_percent' => 'i-cpu',
                            'memory_percent' => 'i-ram',
                            'disk_percent' => 'i-disk',
                        ];

                        $operatorLabels = [
                            '>=' => '≥',
                            '>' => '>',
                            '<=' => '≤',
                            '<' => '<',
                        ];

                        $metricLabel = \App\Models\AlertRule::metricLabel($rule->metric);

                        $metricIcon = $metricIcons[$rule->metric] ?? 'i-sliders';

                        $operator = $operatorLabels[$rule->operator]
                            ?? $rule->operator;

                        $unit = \App\Models\AlertRule::metricUnit($rule->metric);
                    @endphp

                    <article @class(['rule-card', 'is-enabled' => $rule->is_enabled])>
                        <div class="rule-top">
                            <div class="rule-main">
                                <span class="rule-icon">
                                    <svg class="ico"><use href="#{{ $metricIcon }}"/></svg>
                                </span>

                                <div>
                                    <h3 class="rule-name">{{ $rule->name }}</h3>
                                    <div class="rule-description">
                                        {{ $metricLabel }}
                                        · {{ $rule->alerts_count }} geregistreerde alerts
                                    </div>
                                </div>
                            </div>

                            <span @class([
                                'badge',
                                'badge-enabled' => $rule->is_enabled,
                                'badge-disabled' => ! $rule->is_enabled,
                            ])>
                                {{ $rule->is_enabled ? 'Ingeschakeld' : 'Uitgeschakeld' }}
                            </span>
                        </div>

                        <div class="rule-details">
                            <div>
                                <div class="detail-label">Voorwaarde</div>
                                <div class="detail-value">
                                    {{ $operator }}
                                    {{ number_format($rule->threshold, 1, ',', '.') }}{{ $unit }}
                                </div>
                            </div>

                            <div>
                                <div class="detail-label">Vereiste duur</div>
                                <div class="detail-value">{{ $rule->duration_minutes }} minuten</div>
                            </div>

                            <div>
                                <div class="detail-label">Metric</div>
                                <div class="detail-value">{{ $metricLabel }}</div>
                            </div>
                        </div>

                        <div class="rule-actions">
                            <a href="{{ route('alert-rules.edit', $rule) }}" class="button button-sm">
                                <svg class="ico"><use href="#i-edit"/></svg> Bewerken
                            </a>

                            <form
                                method="POST"
                                action="{{ route('alert-rules.destroy', $rule) }}"
                                onsubmit="return confirm('Weet je zeker dat je deze alertregel en de bijbehorende alertgeschiedenis wilt verwijderen?');"
                            >
                                @csrf
                                @method('DELETE')

                                <button class="button button-sm button-danger" type="submit">
                                    <svg class="ico"><use href="#i-trash"/></svg> Verwijderen
                                </button>
                            </form>
                        </div>
                    </article>
                @endforeach
            </div>

            @if ($rules->hasPages())
                <div class="pagination">
                    <span>
                        Resultaten {{ $rules->firstItem() }}–{{ $rules->lastItem() }}
                        van {{ $rules->total() }}
                    </span>

                    <div class="pagination-links">
                        @if ($rules->onFirstPage())
                            <span aria-disabled="true">← Vorige</span>
                        @else
                            <a href="{{ $rules->previousPageUrl() }}">← Vorige</a>
                        @endif

                        @if ($rules->hasMorePages())
                            <a href="{{ $rules->nextPageUrl() }}">Volgende →</a>
                        @else
                            <span aria-disabled="true">Volgende →</span>
                        @endif
                    </div>
                </div>
            @endif
        @endif
    </section>

    
</x-layouts.app>
