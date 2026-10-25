@php use App\Support\Time; @endphp

<x-layouts.app title="Gebruikers">
    <x-slot:subtitle>Alle gebruikers zien alle servers, domeinen en incidenten. Beheerders wijzigen de configuratie.</x-slot:subtitle>

    <div class="grid-2 split">
        <section class="panel">
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th>Gebruiker</th><th>Rol</th><th>2FA</th><th>Meldingen</th><th></th></tr></thead>
                    <tbody>
                        @foreach ($users as $user)
                            <tr>
                                <td><strong>{{ $user->name }}</strong>@if ($user->is(auth()->user())) <span class="chip">jij</span>@endif<span class="cell-sub">{{ $user->email }} · sinds {{ Time::format($user->created_at, 'd-m-Y') }}</span></td>
                                <td><x-status :status="$user->is_admin ? 'info' : 'unknown'" :label="$user->is_admin ? 'Beheerder' : 'Gebruiker'" /></td>
                                <td><x-status :status="$user->hasTwoFactorEnabled() ? 'enabled' : 'disabled'" :label="$user->hasTwoFactorEnabled() ? 'Aan' : 'Uit'" /></td>
                                <td class="small">{{ collect(['e-mail' => $user->notify_email, 'in-app' => $user->notify_in_app])->filter()->keys()->implode(', ') ?: '—' }}</td>
                                <td>
                                    @unless ($user->is(auth()->user()))
                                        <div class="row-actions">
                                            <form method="POST" action="{{ route('users.update', $user) }}" class="inline-form">
                                                @csrf @method('PUT')
                                                <input type="hidden" name="is_admin" value="{{ $user->is_admin ? 0 : 1 }}">
                                                <button class="button button-sm" type="submit">{{ $user->is_admin ? 'Maak gebruiker' : 'Maak beheerder' }}</button>
                                            </form>
                                            <form method="POST" action="{{ route('users.destroy', $user) }}" class="inline-form" data-confirm="Gebruiker {{ $user->email }} verwijderen?">
                                                @csrf @method('DELETE')
                                                <button class="button button-sm button-danger" type="submit">Verwijderen</button>
                                            </form>
                                        </div>
                                    @endunless
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        <section class="panel">
            <div class="section-heading"><div><h2>Gebruiker uitnodigen</h2><p>De gebruiker ontvangt een e-mail om zelf een wachtwoord in te stellen.</p></div></div>
            <form method="POST" action="{{ route('users.store') }}">
                @csrf
                <div class="stack">
                    <div @class(['form-field', 'has-error' => $errors->has('name')])><label for="name">Naam</label><input id="name" name="name" required maxlength="255" value="{{ old('name') }}">@error('name')<span class="form-error">{{ $message }}</span>@enderror</div>
                    <div @class(['form-field', 'has-error' => $errors->has('email')])><label for="email">E-mailadres</label><input id="email" name="email" type="email" required maxlength="255" value="{{ old('email') }}">@error('email')<span class="form-error">{{ $message }}</span>@enderror</div>
                    <label class="check"><input type="hidden" name="is_admin" value="0"><input type="checkbox" name="is_admin" value="1"> Beheerder</label>
                </div>
                <div class="form-footer"><button class="button button-primary" type="submit">Uitnodigen</button></div>
            </form>
        </section>
    </div>
</x-layouts.app>
