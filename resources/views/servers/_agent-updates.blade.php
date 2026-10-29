@php
    use App\Support\Time;

    $u = $agentUpdate;
    $updaterLabels = [
        'active' => ['enabled', 'Updateprogramma actief'],
        'disabled' => ['paused', 'Updates lokaal uitgeschakeld'],
        'stale' => ['warning', 'Updateprogramma geen contact'],
        'missing' => ['unknown', 'Updateprogramma niet gevonden'],
    ];
    [$updaterStatus, $updaterLabel] = $updaterLabels[$u['updater']];
    $requestStatus = [
        'pending' => 'queued', 'in_progress' => 'investigating', 'succeeded' => 'valid',
        'rolled_back' => 'failed', 'rejected' => 'invalid', 'failed' => 'failed', 'expired' => 'expired',
    ];
@endphp

<section class="panel" id="agent-updates">
    <div class="section-heading">
        <div>
            <h2>Agentupdates</h2>
            <p>Alleen releases die zijn ondertekend met de releasesleutel van ServerScope worden geïnstalleerd; de server controleert de handtekening zelf. Mislukt een update, dan zet het updateprogramma de vorige versie terug.</p>
        </div>
        <x-status :status="$updaterStatus" :label="$updaterLabel" />
    </div>

    <dl class="dl" style="margin-top:10px">
        <dt>Geïnstalleerde versie</dt><dd>{{ $u['installed'] ?? 'onbekend' }}</dd>
        <dt>Nieuwste versie</dt><dd>{{ $u['latest']?->version ?? 'nog geen release beschikbaar' }}</dd>
        <dt>Updateprogramma</dt>
        <dd>
            @switch($u['updater'])
                @case('active') Actief (versie {{ $server->updater_version }}), laatste controle {{ Time::relative($server->updater_last_seen_at) }}. @break
                @case('disabled') Updates zijn op de server zelf uitgeschakeld (lokaal uitgeschakeld); ServerScope kan dat niet overrulen. Weer aanzetten op de server: <code>sudo bash serverscope-install.sh --enable-updates</code> @break
                @case('stale') Geen contact sinds {{ Time::relative($server->updater_last_seen_at) }}. Controleer op de server: <code>systemctl status serverscope-updater.timer</code> @break
                @default Niet gevonden. Servers met agent 0.2.0 of een handmatige installatie hebben nog geen updateprogramma: draai install.sh opnieuw op de server (<code>sudo bash serverscope-install.sh</code>, het commando staat onder Servers → Server toevoegen). Er wordt geen nieuwe server aangemaakt.
            @endswitch
        </dd>
        <dt>Automatisch bijwerken</dt><dd>{{ $server->auto_update_enabled ? 'Aan' : 'Uit' }}</dd>
        @if ($u['request'])
            <dt>Laatste update</dt>
            <dd>
                <x-status :status="$requestStatus[$u['request']->status] ?? 'unknown'" :label="$u['request']->statusLabel()" />
                {{ $u['request']->from_version ?? '?' }} → {{ $u['request']->target_version }}
                @if ($u['request']->reasonLabel()) ({{ $u['request']->reasonLabel() }}) @endif
                <span class="cell-sub">
                    {{ $u['request']->source === 'auto' ? 'Automatisch' : 'Aangevraagd door '.($u['request']->requester?->name ?? 'onbekend') }},
                    {{ Time::relative($u['request']->created_at) }}@if ($u['request']->finished_at), afgerond {{ Time::relative($u['request']->finished_at) }}@endif
                </span>
            </dd>
        @endif
    </dl>

    @can('admin')
        @unless ($server->trashed())
            <div class="row-actions">
                @if ($u['blocker'] === null && ! $u['request']?->isOpen())
                    <form method="POST" action="{{ route('servers.agent-update', $server) }}" data-confirm="De agent op {{ $server->name }} bijwerken naar {{ $u['latest']->version }}? De agent herstart even.">
                        @csrf
                        <button class="button button-primary" type="submit">Bijwerken naar {{ $u['latest']->version }}</button>
                    </form>
                @elseif ($u['blocker'] && $u['latest'] && $u['latest']->isNewerThan($u['installed']))
                    <span class="small muted">{{ $u['blocker'] }}</span>
                @endif

                <form method="POST" action="{{ route('servers.auto-update', $server) }}">
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="enabled" value="{{ $server->auto_update_enabled ? '0' : '1' }}">
                    <button class="button" type="submit">{{ $server->auto_update_enabled ? 'Automatisch bijwerken uitzetten' : 'Automatisch bijwerken aanzetten' }}</button>
                </form>
            </div>
        @endunless
    @endcan
</section>
