<x-layouts.app title="Profiel">
    <x-slot:subtitle>Je account, wachtwoord en meldingsvoorkeuren.</x-slot:subtitle>

    <div class="grid-2">
        <div class="stack">
            <section class="panel">
                <div class="section-heading"><div><h2>Account</h2></div></div>
                <form method="POST" action="{{ route('profile.update') }}">
                    @csrf @method('PUT')
                    <div class="form-cols">
                        <div @class(['form-field', 'has-error' => $errors->has('name')])>
                            <label for="name">Naam</label>
                            <input id="name" name="name" type="text" required maxlength="255" value="{{ old('name', $user->name) }}" autocomplete="name">
                            @error('name')<span class="form-error">{{ $message }}</span>@enderror
                        </div>
                        <div @class(['form-field', 'has-error' => $errors->has('email')])>
                            <label for="email">E-mailadres</label>
                            <input id="email" name="email" type="email" required maxlength="255" value="{{ old('email', $user->email) }}" autocomplete="email">
                            @error('email')<span class="form-error">{{ $message }}</span>@enderror
                        </div>
                    </div>
                    <div class="form-footer"><button class="button button-primary" type="submit">Opslaan</button></div>
                </form>
            </section>

            <section class="panel">
                <div class="section-heading"><div><h2>Wachtwoord wijzigen</h2><p>Minimaal 12 tekens met hoofdletters, kleine letters en cijfers. Andere sessies worden afgemeld.</p></div></div>
                <form method="POST" action="{{ route('profile.password') }}">
                    @csrf @method('PUT')
                    <div class="form-cols">
                        <div @class(['form-field full', 'has-error' => $errors->password->has('current_password')])>
                            <label for="current_password">Huidig wachtwoord</label>
                            <input id="current_password" name="current_password" type="password" required autocomplete="current-password">
                            @error('current_password', 'password')<span class="form-error">{{ $message }}</span>@enderror
                        </div>
                        <div @class(['form-field', 'has-error' => $errors->password->has('password')])>
                            <label for="password">Nieuw wachtwoord</label>
                            <input id="password" name="password" type="password" required autocomplete="new-password">
                            @error('password', 'password')<span class="form-error">{{ $message }}</span>@enderror
                        </div>
                        <div class="form-field">
                            <label for="password_confirmation">Herhaal nieuw wachtwoord</label>
                            <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password">
                        </div>
                    </div>
                    <div class="form-footer"><button class="button" type="submit">Wachtwoord wijzigen</button></div>
                </form>
            </section>

            @if (Route::has('two-factor.show'))
                @include('profile._two_factor')
            @endif

            @if (Route::has('api-tokens.index'))
                <section class="panel">
                    <div class="section-heading"><div><h2>API-tokens</h2><p>Persoonlijke tokens voor de ServerScope API.</p></div><a class="button button-sm" href="{{ route('api-tokens.index') }}">Beheren</a></div>
                </section>
            @endif
        </div>

        <section class="panel">
            <div class="section-heading"><div><h2>Meldingen</h2><p>Je ontvangt alleen meldingen bij het openen, escaleren, heropenen en oplossen van incidenten — nooit voor elke mislukte controle.</p></div></div>
            <form method="POST" action="{{ route('profile.notifications') }}">
                @csrf @method('PUT')
                <div class="stack">
                    @foreach ([
                        'notify_email' => ['E-mail', 'Meldingen per e-mail aan '.$user->email.(config('serverscope.alert_mail_enabled') ? '' : ' (e-mail is uitgeschakeld door de beheerder)')],
                        'notify_in_app' => ['In de app', 'Meldingen onder “Meldingen” in de zijbalk.'],
                        'notify_resolved' => ['Herstelmeldingen', 'Ook een melding wanneer een incident is opgelost.'],
                        'notify_dns_changes' => ['DNS- en inhoudswijzigingen', 'Melding bij gewijzigde A-, AAAA-, CNAME-, MX-, NS- of CAA-records en bij significante inhoudswijzigingen van pagina\'s waarvoor dat is ingeschakeld.'],
                    ] as $key => [$label, $help])
                        <label class="check">
                            <input type="hidden" name="{{ $key }}" value="0">
                            <input type="checkbox" name="{{ $key }}" value="1" @checked(old($key, $user->{$key}))>
                            <span><strong>{{ $label }}</strong><span class="form-help" style="display:block">{{ $help }}</span></span>
                        </label>
                    @endforeach
                    <div class="form-field">
                        <label for="notify_min_severity">Minimale ernst</label>
                        <select id="notify_min_severity" name="notify_min_severity">
                            @foreach (['info' => 'Alles (laag en hoger)', 'warning' => 'Gemiddeld en hoger', 'high' => 'Hoog en kritiek', 'critical' => 'Alleen kritiek'] as $v => $l)
                                <option value="{{ $v }}" @selected(old('notify_min_severity', $user->notify_min_severity) === $v)>{{ $l }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="form-footer"><button class="button button-primary" type="submit">Voorkeuren opslaan</button></div>
            </form>
            <form method="POST" action="{{ route('profile.notifications.test') }}" style="margin-top:12px">
                @csrf
                <button class="button button-sm" type="submit"><svg class="ico"><use href="#i-bell"/></svg> Testmelding versturen</button>
            </form>
        </section>
    </div>
</x-layouts.app>
