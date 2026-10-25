<x-layouts.app :title="'Nieuwe alertregel'">
    <x-slot:styles>
        .actions form { margin: 0; }
        .notice {
            margin-bottom: 16px; padding: 14px 16px;
            border: 1px solid #7f1d1d; border-radius: 10px;
            background: #2a121a; color: var(--red);
        }
        .notice ul { margin: 8px 0 0; padding-left: 18px; }
        .panel {
            max-width: 850px; padding: 22px;
            border: 1px solid var(--border); border-radius: var(--radius);
            background: var(--panel);
        }
        .panel-head { display: flex; align-items: center; gap: 13px; margin-bottom: 22px; }
        .panel-icon {
            display: grid; place-items: center;
            width: 44px; height: 44px; flex: 0 0 44px;
            border: 1px solid color-mix(in srgb, var(--cyan) 45%, transparent);
            border-radius: 12px;
            background: color-mix(in srgb, var(--cyan) 14%, #0b1424);
            color: var(--cyan); font-size: 22px;
        }
        .panel-head h2 { margin: 0; font-size: 17px; }
        .panel-head p { margin: 3px 0 0; color: var(--muted); font-size: 12px; }
        @media (max-width: 850px) {
    .panel { padding: 16px; }
}
    </x-slot:styles>

    <x-slot:breadcrumb>
        <span>›</span> Alertregels <span>›</span> Nieuw
    </x-slot:breadcrumb>

    <x-slot:pageTitle>Nieuwe alertregel</x-slot:pageTitle>
    <x-slot:subtitle>Stel een nieuwe voorwaarde in voor je infrastructuurmonitoring.</x-slot:subtitle>

    <x-slot:actions>

        <a href="{{ route('alert-rules.index') }}" class="button">
            <svg class="ico"><use href="#i-arrow-left"/></svg> Terug naar regels
        </a>
    </x-slot:actions>


    <section class="panel">
        <div class="panel-head">
            <span class="panel-icon"><svg class="ico"><use href="#i-plus"/></svg></span>
            <div>
                <h2>Configuratie</h2>
                <p>Velden met een verplichte invoer moeten worden ingevuld.</p>
            </div>
        </div>

        @include('alert-rules.form', ['rule' => $rule])
    </section>

    
</x-layouts.app>
