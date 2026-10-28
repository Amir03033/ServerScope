<x-layouts.guest title="Account aanmaken">
    <h1>Account aanmaken</h1>
    <p class="muted">Je krijgt een eigen team. Alleen leden van je team zien je servers en domeinen.</p>

    @if ($errors->any())
        <div class="error" role="alert">{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ route('register') }}">
        @csrf
        <label for="name">Naam</label>
        <input id="name" type="text" name="name" value="{{ old('name') }}" autocomplete="name" required autofocus>
        <label for="team">Bedrijf of teamnaam</label>
        <input id="team" type="text" name="team" value="{{ old('team') }}" autocomplete="organization" required>
        <label for="email">E-mailadres</label>
        <input id="email" type="email" name="email" value="{{ old('email') }}" autocomplete="username" required>
        <label for="password">Wachtwoord</label>
        <input id="password" type="password" name="password" autocomplete="new-password" required>
        <label for="password_confirmation">Herhaal wachtwoord</label>
        <input id="password_confirmation" type="password" name="password_confirmation" autocomplete="new-password" required>
        <button type="submit">Account aanmaken</button>
    </form>
    <div class="links"><a href="{{ route('login') }}">Ik heb al een account</a></div>
</x-layouts.guest>
