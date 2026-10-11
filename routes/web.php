<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ServerRegistrationController;
use App\Http\Middleware\AuthenticateAgent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ServerDetailController;
use App\Http\Controllers\AlertController;
use App\Http\Controllers\AlertRuleController;

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

    Route::get('/alerts', AlertController::class)->name('alerts.index');

    Route::get('/alert-rules', [AlertRuleController::class, 'index'])
        ->name('alert-rules.index');

    Route::get('/alert-rules/create', [AlertRuleController::class, 'create'])
        ->name('alert-rules.create');

    Route::post('/alert-rules', [AlertRuleController::class, 'store'])
        ->name('alert-rules.store');

    Route::get('/alert-rules/{alertRule}/edit', [AlertRuleController::class, 'edit'])
        ->name('alert-rules.edit');

    Route::put('/alert-rules/{alertRule}', [AlertRuleController::class, 'update'])
        ->name('alert-rules.update');

    Route::delete('/alert-rules/{alertRule}', [AlertRuleController::class, 'destroy'])
        ->name('alert-rules.destroy');

    Route::post('/logout', [
        AuthenticatedSessionController::class,
        'destroy',
    ])->name('logout');

    Route::post('/servers/register', [
        ServerRegistrationController::class,
        'store',
    ])->name('servers.register');
});
