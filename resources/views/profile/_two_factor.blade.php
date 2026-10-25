@php $user = auth()->user(); @endphp

<section class="panel" id="two-factor">
    <div class="section-heading">
        <div><h2>Tweestapsverificatie</h2><p>Bescherm je account met een code uit een authenticator-app (TOTP).</p></div>
        <x-status :status="$user->hasTwoFactorEnabled() ? 'enabled' : 'disabled'" />
    </div>

    @if (session('recovery_codes'))
        <div class="secret-box">
            <strong>Bewaar deze herstelcodes</strong>
            <p class="small" style="margin:6px 0 10px">Elke code werkt één keer als je je telefoon kwijt bent. Ze worden niet opnieuw getoond.</p>
            <div class="mono" style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:6px">
                @foreach (session('recovery_codes') as $code)<span>{{ $code }}</span>@endforeach
            </div>
        </div>
    @endif

    @if ($user->hasTwoFactorEnabled())
        <p class="small muted">Ingeschakeld op {{ \App\Support\Time::format($user->two_factor_confirmed_at) }} · {{ count($user->two_factor_recovery_codes ?? []) }} herstelcodes over.</p>
        <form method="POST" action="{{ route('two-factor.recovery-codes') }}" style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px">
            @csrf
            <input class="input" type="password" name="current_password" placeholder="Huidig wachtwoord" required style="flex:1;min-width:160px" autocomplete="current-password" aria-label="Huidig wachtwoord">
            <button class="button" type="submit">Nieuwe herstelcodes</button>
        </form>
        <form method="POST" action="{{ route('two-factor.show') }}" style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px" data-confirm="Tweestapsverificatie uitschakelen?">
            @csrf @method('DELETE')
            <input class="input" type="password" name="current_password" placeholder="Huidig wachtwoord" required style="flex:1;min-width:160px" autocomplete="current-password" aria-label="Huidig wachtwoord">
            <button class="button button-danger" type="submit">Uitschakelen</button>
        </form>
    @elseif ($user->two_factor_secret)
        <ol class="small" style="padding-left:18px;line-height:1.7">
            <li>Open je authenticator-app (bijv. 1Password, Authy, Google Authenticator) en voeg een account toe.</li>
            <li>Kies “sleutel invoeren” en gebruik deze sleutel: <code class="mono" style="user-select:all">{{ trim(chunk_split($user->two_factor_secret, 4, ' ')) }}</code> (tijdgebaseerd, 6 cijfers).</li>
            <li>Op je telefoon kun je ook <a href="{{ \App\Support\Totp::uri($user->two_factor_secret, $user->email) }}">deze link</a> openen.</li>
            <li>Voer hieronder de code in die de app toont.</li>
        </ol>
        <form method="POST" action="{{ route('two-factor.confirm') }}" style="display:flex;gap:8px;flex-wrap:wrap">
            @csrf
            <input class="input" name="code" inputmode="numeric" autocomplete="one-time-code" placeholder="123456" required style="width:140px" aria-label="Verificatiecode">
            <button class="button button-primary" type="submit">Bevestigen en inschakelen</button>
        </form>
        @error('code', 'twoFactor')<p class="form-error">{{ $message }}</p>@enderror
    @else
        <form method="POST" action="{{ route('two-factor.enable') }}">
            @csrf
            <button class="button button-primary" type="submit"><svg class="ico"><use href="#i-shield"/></svg> Tweestapsverificatie instellen</button>
        </form>
    @endif
    @error('current_password', 'twoFactor')<p class="form-error">{{ $message }}</p>@enderror
</section>
