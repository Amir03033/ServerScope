@php
    // Self-contained on purpose: no Vite assets, database, session or
    // JavaScript, so it also renders in maintenance mode and when the
    // database or build is unavailable. Only translations and config.
    $referer = request()->headers->get('referer');
    $back = is_string($referer) && parse_url($referer, PHP_URL_HOST) === request()->getHost() && $referer !== request()->fullUrl() ? $referer : null;
    $publicUrl = config('serverscope.public_url');
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <meta name="color-scheme" content="dark">
    <title>{{ $code }} · {{ $title }} — ServerScope</title>
    <style>
        :root { --bg: #0a0a0b; --surface: #111113; --line: #232326; --text: #ededef; --muted: #a1a1aa; --accent: #7aa2f7; --on-accent: #0a0a0b; --tone: {{ $tone ?? '#7aa2f7' }}; }
        * { box-sizing: border-box; }
        html, body { margin: 0; min-height: 100%; background: var(--bg); color: var(--text); font: 16px/1.6 ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
        body { display: grid; place-items: center; min-height: 100vh; padding: 24px 16px;
            background-image: linear-gradient(var(--line) 1px, transparent 1px), linear-gradient(90deg, var(--line) 1px, transparent 1px);
            background-size: 48px 48px; background-position: center; }
        main { width: 100%; max-width: 560px; background: color-mix(in srgb, var(--surface) 94%, transparent); border: 1px solid var(--line); border-radius: 14px; padding: 32px 28px; box-shadow: 0 20px 60px rgb(0 0 0 / 45%); }
        .brand { display: flex; align-items: center; gap: 10px; color: var(--muted); font-size: 14px; margin-bottom: 28px; }
        .brand svg { width: 22px; height: 22px; color: var(--accent); }
        .signal { display: flex; align-items: center; gap: 10px; font: 600 13px/1 ui-monospace, SFMono-Regular, Menlo, monospace; color: var(--tone); letter-spacing: .04em; text-transform: uppercase; }
        .dot { width: 9px; height: 9px; border-radius: 50%; background: var(--tone); box-shadow: 0 0 0 4px color-mix(in srgb, var(--tone) 22%, transparent); }
        @media (prefers-reduced-motion: no-preference) { .dot { animation: pulse 2.4s ease-in-out infinite; } @keyframes pulse { 50% { opacity: .35; } } }
        .code { font: 700 64px/1 ui-monospace, SFMono-Regular, Menlo, monospace; margin: 18px 0 6px; letter-spacing: -.02em; }
        h1 { font-size: 24px; line-height: 1.3; margin: 0 0 10px; }
        p { color: var(--muted); margin: 0 0 14px; }
        .trace { font: 12px/1.6 ui-monospace, SFMono-Regular, Menlo, monospace; color: var(--muted); border-left: 2px solid var(--line); padding-left: 12px; margin: 18px 0 24px; }
        .actions { display: flex; flex-wrap: wrap; gap: 10px; }
        a.btn, button.btn { display: inline-flex; align-items: center; min-height: 44px; padding: 0 18px; border-radius: 8px; border: 1px solid var(--line); color: var(--text); text-decoration: none; font: 600 14px/1 inherit; background: transparent; cursor: pointer; }
        a.btn.primary { background: var(--accent); border-color: var(--accent); color: var(--on-accent); }
        a.btn:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }
        a.btn:hover { border-color: var(--accent); }
    </style>
</head>
<body>
<main>
    <div class="brand"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="4" width="18" height="6" rx="1.5"/><rect x="3" y="14" width="18" height="6" rx="1.5"/><path d="M7 7h.01M7 17h.01"/></svg> ServerScope</div>
    <div class="signal" role="status"><span class="dot" aria-hidden="true"></span>{{ $signal }}</div>
    <div class="code" aria-hidden="true">{{ $code }}</div>
    <h1>{{ $title }}</h1>
    <p>{{ $text }}</p>
    @isset($extra)<p>{{ $extra }}</p>@endisset
    <div class="trace" aria-hidden="true">{{ $trace ?? 'status='.$code.' · '.now()->utc()->format('Y-m-d H:i').' UTC' }}</div>
    <div class="actions">
        @foreach ($actions ?? ['dashboard', 'home', 'back'] as $action)
            @if ($action === 'dashboard')
                <a class="btn primary" href="{{ url('/') }}">{{ __('errors.actions.dashboard') }}</a>
            @elseif ($action === 'home' && $publicUrl)
                <a class="btn" href="{{ $publicUrl }}" rel="noopener">{{ __('errors.actions.home') }}</a>
            @elseif ($action === 'back' && $back)
                <a class="btn" href="{{ $back }}">{{ __('errors.actions.back') }}</a>
            @elseif ($action === 'retry')
                <a class="btn primary" href="{{ request()->fullUrl() }}">{{ __('errors.actions.retry') }}</a>
            @elseif ($action === 'status' && config('serverscope.status_url'))
                <a class="btn" href="{{ config('serverscope.status_url') }}" rel="noopener">{{ __('errors.actions.status') }}</a>
            @elseif ($action === 'login')
                <a class="btn" href="{{ url('/login') }}">{{ __('errors.actions.login') }}</a>
            @endif
        @endforeach
    </div>
</main>
</body>
</html>
