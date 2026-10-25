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
use App\Http\Controllers\AgentCredentialController;
use App\Http\Controllers\ServerController;
use App\Http\Controllers\ServerMaintenanceController;

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

    Route::get('/servers', [ServerController::class, 'index'])->name('servers.index');
    Route::get('/servers/create', [ServerController::class, 'create'])->name('servers.create');
    Route::post('/servers', [ServerController::class, 'store'])->name('servers.store');

    Route::get('/servers/{server}', ServerDetailController::class)
        ->withTrashed()
        ->name('servers.show');

    Route::get('/servers/{server}/edit', [ServerController::class, 'edit'])->name('servers.edit');
    Route::put('/servers/{server}', [ServerController::class, 'update'])->name('servers.update');
    Route::delete('/servers/{server}', [ServerController::class, 'destroy'])->name('servers.destroy');
    Route::post('/servers/{server}/restore', [ServerController::class, 'restore'])
        ->withTrashed()
        ->name('servers.restore');
    Route::delete('/servers/{server}/permanent', [ServerController::class, 'forceDestroy'])
        ->withTrashed()
        ->name('servers.force-destroy');

    Route::post('/servers/{server}/maintenance', [ServerMaintenanceController::class, 'store'])
        ->name('servers.maintenance.start');
    Route::delete('/servers/{server}/maintenance', [ServerMaintenanceController::class, 'destroy'])
        ->name('servers.maintenance.end');

    Route::post('/servers/{server}/credentials', [AgentCredentialController::class, 'store'])
        ->name('servers.credentials.store');
    Route::post('/servers/{server}/credentials/rotate', [AgentCredentialController::class, 'rotate'])
        ->name('servers.credentials.rotate');
    Route::delete('/servers/{server}/credentials/{agentCredential}', [AgentCredentialController::class, 'destroy'])
        ->scopeBindings()
        ->name('servers.credentials.destroy');

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
