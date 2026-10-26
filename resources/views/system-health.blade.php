<x-layouts.app title="Systeemstatus">
    <x-slot:subtitle>De gezondheid van ServerScope zelf: zonder scheduler en queue-worker worden er geen controles uitgevoerd en geen meldingen verstuurd.</x-slot:subtitle>

    <section class="panel">
        <div class="section-heading">
            <div><h2>Overzicht</h2><p>Wordt elke minuut opnieuw beoordeeld. Zie ook de <a href="{{ config('serverscope.docs_url') ?: '#' }}" target="_blank" rel="noopener">documentatie</a> en docs/DEPLOYMENT.md.</p></div>
            <x-status :status="$report['status']" :label="['healthy' => 'In orde', 'warning' => 'Aandacht nodig', 'critical' => 'Verstoord'][$report['status']]" />
        </div>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Onderdeel</th><th>Status</th><th>Toelichting</th></tr></thead>
                <tbody>
                    @foreach ($report['checks'] as $check)
                        <tr>
                            <td><strong>{{ $check['label'] }}</strong></td>
                            <td><x-status :status="$check['status']" :label="['healthy' => 'In orde', 'warning' => 'Aandacht', 'critical' => 'Probleem'][$check['status']]" /></td>
                            <td class="small">{{ $check['detail'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
</x-layouts.app>
