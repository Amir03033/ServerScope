@php use App\Models\ApiToken; use App\Support\Time; @endphp

<x-layouts.app title="API-tokens">
    <x-slot:breadcrumb><span>›</span> <a href="{{ route('profile.edit') }}">Profiel</a> <span>›</span> API-tokens</x-slot:breadcrumb>
    <x-slot:subtitle>Persoonlijke tokens voor de REST API (<code>/api/v1</code>). Een token heeft dezelfde rechten als jouw account, beperkt tot de gekozen bevoegdheden.</x-slot:subtitle>

    @if (session('api_token'))
        <x-secret-token :token="session('api_token')" title="Je nieuwe API-token" id="api-token">
            <p class="small" style="margin:8px 0 0">Gebruik: <code>Authorization: Bearer &lt;token&gt;</code></p>
        </x-secret-token>
    @endif

    <div class="grid-2 split">
        <section class="panel">
            <div class="section-heading"><div><h2>Tokens</h2></div></div>
            @if ($tokens->isEmpty())
                <x-empty-state icon="i-key" title="Nog geen tokens">Maak een token aan om de API te gebruiken.</x-empty-state>
            @else
                <div class="table-wrap">
                    <table class="data-table">
                        <thead><tr><th>Naam</th><th>Bevoegdheden</th><th>Laatst gebruikt</th><th>Status</th><th></th></tr></thead>
                        <tbody>
                            @foreach ($tokens as $token)
                                <tr>
                                    <td>{{ $token->name }}<span class="cell-sub mono">{{ $token->prefix }}…</span></td>
                                    <td>@foreach ($token->abilities as $a)<span class="chip">{{ $a }}</span> @endforeach</td>
                                    <td class="small">{{ Time::relative($token->last_used_at) }}</td>
                                    <td>
                                        @if ($token->revoked_at)<x-status status="closed" label="Ingetrokken" />
                                        @elseif (! $token->isActive())<x-status status="expired" />
                                        @else<x-status status="valid" label="Actief" />@if ($token->expires_at)<span class="cell-sub">tot {{ Time::format($token->expires_at, 'd-m-Y') }}</span>@endif
                                        @endif
                                    </td>
                                    <td>
                                        @if ($token->isActive())
                                            <form method="POST" action="{{ route('api-tokens.destroy', $token) }}" data-confirm="Token '{{ $token->name }}' intrekken?" class="row-actions">
                                                @csrf @method('DELETE')
                                                <button class="button button-sm button-danger" type="submit">Intrekken</button>
                                            </form>
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
            <div class="section-heading"><div><h2>Nieuw token</h2></div></div>
            <form method="POST" action="{{ route('api-tokens.store') }}">
                @csrf
                <div class="stack">
                    <div class="form-field"><label for="name">Naam</label><input id="name" name="name" maxlength="100" required placeholder="Bijv. Grafana of n8n"></div>
                    <div class="form-field">
                        <span class="form-label">Bevoegdheden</span>
                        @foreach (ApiToken::ABILITIES as $value => $label)
                            <label class="check"><input type="checkbox" name="abilities[]" value="{{ $value }}" @checked($value === 'read') @disabled($value === 'read')> {{ $label }}</label>
                        @endforeach
                        <input type="hidden" name="abilities[]" value="read">
                    </div>
                    <div class="form-field"><label for="expires_in_days">Geldigheid</label>
                        <select id="expires_in_days" name="expires_in_days"><option value="90">90 dagen</option><option value="30">30 dagen</option><option value="365">1 jaar</option><option value="">Onbeperkt</option></select>
                    </div>
                </div>
                <div class="form-footer"><button class="button button-primary" type="submit">Token aanmaken</button></div>
            </form>
        </section>
    </div>
</x-layouts.app>
