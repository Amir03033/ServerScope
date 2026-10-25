<x-layouts.guest title="Tweestapsverificatie">
    <h1>Tweestapsverificatie</h1>
    <p class="muted">Voer de 6-cijferige code uit je authenticator-app in, of gebruik een herstelcode.</p>

    @if ($errors->any())
        <div class="error" role="alert">{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ route('two-factor.challenge.store') }}">
        @csrf
        <label for="code">Verificatiecode</label>
        <input id="code" name="code" inputmode="numeric" pattern="[0-9 ]*" autocomplete="one-time-code" autofocus>
        <label for="recovery_code">of herstelcode</label>
        <input id="recovery_code" name="recovery_code" autocomplete="off">
        <button type="submit">Verifiëren</button>
    </form>
    <form method="POST" action="{{ route('two-factor.challenge.cancel') }}">
        @csrf
        <button type="submit" class="secondary">Annuleren</button>
    </form>
</x-layouts.guest>
