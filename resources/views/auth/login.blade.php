
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Inloggen — ServerScope</title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 24px;
            background: #080d18;
            color: #e5e7eb;
            font: 15px system-ui, sans-serif;
        }
        .panel {
            width: 100%;
            max-width: 420px;
            padding: 36px;
            border: 1px solid #263246;
            border-radius: 18px;
            background: #101827;
        }
        .brand { color: #67e8f9; font-weight: 750; letter-spacing: .5px; }
        h1 { margin: 28px 0 8px; font-size: 28px; }
        .muted { color: #94a3b8; line-height: 1.6; }
        label { display: block; margin: 20px 0 8px; font-size: 13px; }
        input {
            width: 100%;
            padding: 12px 14px;
            color: #f8fafc;
            background: #0b1220;
            border: 1px solid #334155;
            border-radius: 9px;
            font: inherit;
        }
        input:focus { outline: 2px solid #22d3ee; outline-offset: 2px; }
        button {
            width: 100%;
            margin-top: 24px;
            padding: 13px;
            border: 0;
            border-radius: 9px;
            background: #22d3ee;
            color: #08202a;
            font: inherit;
            font-weight: 700;
            cursor: pointer;
        }
        .error { color: #fca5a5; margin-top: 14px; }
        .footer { margin-top: 26px; font-size: 12px; color: #64748b; }
    </style>
</head>
<body>
<main class="panel">
    <div class="brand">SERVERSCOPE / MONITORING</div>
    <h1>Welkom terug</h1>
    <p class="muted">Log in om je servers en monitoringgegevens te bekijken.</p>

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

    <div class="footer">ServerScope · Secure server monitoring</div>
</main>
</body>
</html>
