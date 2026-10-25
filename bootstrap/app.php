<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);

        // Plesk serves Laravel behind its own nginx/Apache proxy; trust it so
        // request()->ip() and isSecure() reflect the real client.
        $middleware->trustProxies(at: env('TRUSTED_PROXIES', '127.0.0.1,::1'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // The API always answers in JSON, also for 404/405/500.
        $exceptions->shouldRenderJsonWhen(fn ($request) => $request->is('api/*') || $request->expectsJson());
    })->create();
