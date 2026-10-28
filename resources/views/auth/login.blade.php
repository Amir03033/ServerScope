<x-layouts.guest title="Inloggen">
    <h1>Welkom terug</h1>
    <p class="muted">Log in om je servers en monitoringgegevens te bekijken.</p>

    @if (session('status'))
        <div class="status" role="status">{{ session('status') }}</div>
    @endif
    @if (session('error'))
        <div class="error" role="alert">{{ session('error') }}</div>
    @endif

    @if ($errors->any())
        <div class="error" role="alert">
            {{ $errors->first() }}
        </div>
    @endif

    <form method="POST" action="{{ route('login') }}">
        @csrf

        <label for="email">E-mailadres</label>
        <input
            id="email"
            type="email"
            name="email"
            value="{{ old('email') }}"
            autocomplete="username"
            required
            autofocus
        >

        <label for="password">Wachtwoord</label>
        <input
            id="password"
            type="password"
            name="password"
            autocomplete="current-password"
            required
        >

        <label>
            <input
                type="checkbox"
                name="remember"
                value="1"
                style="width:auto"
            >
            Onthoud mij
        </label>

        <button type="submit">Inloggen</button>
    </form>

    <div class="links"><a href="{{ route('password.request') }}">Wachtwoord vergeten?</a></div>
</x-layouts.guest>
