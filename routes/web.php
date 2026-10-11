<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ServerRegistrationController;
use App\Http\Middleware\AuthenticateAgent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ServerDetailController;

Route::redirect('/', '/dashboard');

Route::middleware('guest')->group(function () {
    Route::get('/login', [
        AuthenticatedSessionController::class,
        'create',
    ])->name('login');

    Route::post('/login', [
        AuthenticatedSessionController::class,
        'store',
    ])->middleware('throttle:10,1');
});

Route::get('/agent/ping', function (Request $request) {
    $server = $request->attributes->get('agentServer');

    return response()->json([
        'authenticated' => true,
        'server_id' => $server->id,
        'server_name' => $server->name,
    ]);
})->middleware(AuthenticateAgent::class)->name('agent.ping');

Route::middleware('auth')->group(function () {
    Route::get('/dashboard', DashboardController::class)
        ->name('dashboard');

    Route::get('/servers/{server}', ServerDetailController::class)
        ->name('servers.show');

    Route::post('/logout', [
        AuthenticatedSessionController::class,
        'destroy',
    ])->name('logout');

    Route::post('/servers/register', [
        ServerRegistrationController::class,
        'store',
    ])->name('servers.register');
});
