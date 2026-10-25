<x-layouts.guest title="Nieuw wachtwoord">
    <h1>Nieuw wachtwoord</h1>
    <p class="muted">Minimaal 12 tekens met hoofdletters, kleine letters en cijfers.</p>

    @if ($errors->any())
        <div class="error" role="alert">{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ route('password.update') }}">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <label for="email">E-mailadres</label>
        <input id="email" type="email" name="email" value="{{ old('email', $email) }}" autocomplete="username" required>
        <label for="password">Nieuw wachtwoord</label>
        <input id="password" type="password" name="password" autocomplete="new-password" required autofocus>
        <label for="password_confirmation">Herhaal wachtwoord</label>
        <input id="password_confirmation" type="password" name="password_confirmation" autocomplete="new-password" required>
        <button type="submit">Wachtwoord opslaan</button>
    </form>
</x-layouts.guest>
