<x-layouts.app title="Agentreleases">
    <x-slot:subtitle>Ondertekende agentreleases en hoeveel servers elke versie draaien (alleen aantallen, over alle teams). Releases importeren of terugtrekken gaat met <code>php artisan serverscope:release:import</code> en <code>serverscope:release:withdraw</code>.</x-slot:subtitle>

    <section class="panel">
        <div class="section-heading"><div><h2>Releases</h2></div></div>
        @if ($releases->isEmpty())
            <x-empty-state icon="i-pulse" title="Nog geen releases">Importeer de eerste ondertekende release (zie docs/RELEASE_KEYS.md).</x-empty-state>
        @else
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th>Versie</th><th>Datum</th><th>Sleutel-ID</th><th>Commit</th><th>SHA-256</th><th>Status</th></tr></thead>
                    <tbody>
                        @foreach ($releases as $release)
                            <tr>
                                <td><strong>{{ $release->version }}</strong></td>
                                <td class="nowrap">{{ \App\Support\Time::format($release->released_at) }}</td>
                                <td class="mono">{{ $release->key_id }}</td>
                                <td class="mono" title="{{ $release->commit }}">{{ substr($release->commit, 0, 12) }}</td>
                                <td class="mono" title="{{ $release->sha256 }}">{{ substr($release->sha256, 0, 16) }}…</td>
                                <td>
                                    @if ($release->withdrawn_at)
                                        <x-status status="expired" label="Teruggetrokken" /> <span class="cell-sub">{{ $release->withdraw_reason }}</span>
                                    @else
                                        <x-status status="valid" label="Beschikbaar" />
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <section class="panel">
        <div class="section-heading"><div><h2>Servers per versie</h2><p>{{ $total }} server(s) in totaal.</p></div></div>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Agentversie</th><th class="num">Servers</th><th class="num">Met actief updateprogramma</th></tr></thead>
                <tbody>
                    @foreach ($versions as $row)
                        <tr><td>{{ $row['version'] }}</td><td class="num">{{ $row['servers'] }}</td><td class="num">{{ $row['with_updater'] }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
</x-layouts.app>
