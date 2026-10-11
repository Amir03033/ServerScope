
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard — ServerScope</title>
    <style>
        body {
            margin: 0;
            padding: 32px;
            background: #080d18;
            color: #e5e7eb;
            font: 15px system-ui, sans-serif;
        }
        main { max-width: 1100px; margin: 0 auto; }
        .muted { color: #94a3b8; }
        .panel {
            margin-top: 28px;
            padding: 24px;
            border: 1px solid #263246;
            border-radius: 14px;
            background: #101827;
        }
        button {
            padding: 10px 16px;
            border: 1px solid #334155;
            border-radius: 8px;
            background: #172235;
            color: white;
            cursor: pointer;
        }
    </style>
</head>
<body>
<main>
    <p style="color:#67e8f9;font-weight:700">SERVERSCOPE</p>
    <h1>Dashboard</h1>
    <p class="muted">
        Ingelogd als {{ auth()->user()->email }}
    </p>

    <section class="panel">
        <h2>Monitoring wordt voorbereid</h2>
        <p class="muted">
            De authenticatie werkt hier als basis.
            Serverregistratie en live metingen volgen in de volgende fasen.
        </p>
    </section>

    <form method="POST" action="{{ route('logout') }}">
        @csrf
        <button type="submit">Uitloggen</button>
    </form>
</main>
</body>
</html>
