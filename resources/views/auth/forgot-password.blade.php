<x-layouts.guest title="Wachtwoord vergeten">
    <h1>Wachtwoord vergeten</h1>
    <p class="muted">Vul je e-mailadres in. Als er een account bij hoort, ontvang je een link om een nieuw wachtwoord in te stellen.</p>

    @if (session('status'))
        <div class="status" role="status">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="error" role="alert">{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ route('password.email') }}">
        @csrf
        <label for="email">E-mailadres</label>
        <input id="email" type="email" name="email" value="{{ old('email') }}" autocomplete="username" required autofocus>
        <button type="submit">Resetlink versturen</button>
    </form>
    <div class="links"><a href="{{ route('login') }}">Terug naar inloggen</a></div>
</x-layouts.guest>
