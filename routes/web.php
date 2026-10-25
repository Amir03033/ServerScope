<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\TwoFactorController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ServerRegistrationController;
use App\Http\Middleware\AuthenticateAgent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ServerDetailController;
use App\Http\Controllers\AlertController;
use App\Http\Controllers\AlertRuleController;
use App\Http\Controllers\ApiTokenController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\AgentCredentialController;
use App\Http\Controllers\DomainController;
use App\Http\Controllers\IncidentController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\ServerController;
use App\Http\Controllers\ServerMaintenanceController;
use App\Http\Controllers\WebhookController;

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

    Route::get('/forgot-password', [PasswordResetController::class, 'create'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetController::class, 'store'])->middleware('throttle:5,1')->name('password.email');
    Route::get('/reset-password/{token}', [PasswordResetController::class, 'edit'])->name('password.reset');
    Route::post('/reset-password', [PasswordResetController::class, 'update'])->middleware('throttle:5,1')->name('password.update');

    Route::get('/two-factor-challenge', [TwoFactorController::class, 'challenge'])->name('two-factor.challenge');
    Route::post('/two-factor-challenge', [TwoFactorController::class, 'verify'])->middleware('throttle:10,1')->name('two-factor.challenge.store');
    Route::post('/two-factor-challenge/cancel', [TwoFactorController::class, 'cancel'])->name('two-factor.challenge.cancel');
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

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('/profile/password', [ProfileController::class, 'password'])->middleware('throttle:6,1')->name('profile.password');
    Route::put('/profile/notifications', [ProfileController::class, 'notifications'])->name('profile.notifications');
    Route::post('/profile/notifications/test', [ProfileController::class, 'testNotification'])->name('profile.notifications.test');

    Route::post('/profile/two-factor', [TwoFactorController::class, 'enable'])->name('two-factor.enable');
    Route::post('/profile/two-factor/confirm', [TwoFactorController::class, 'confirm'])->middleware('throttle:10,1')->name('two-factor.confirm');
    Route::post('/profile/two-factor/recovery-codes', [TwoFactorController::class, 'recoveryCodes'])->middleware('throttle:6,1')->name('two-factor.recovery-codes');
    Route::delete('/profile/two-factor', [TwoFactorController::class, 'disable'])->middleware('throttle:6,1')->name('two-factor.show');

    Route::get('/profile/api-tokens', [ApiTokenController::class, 'index'])->name('api-tokens.index');
    Route::post('/profile/api-tokens', [ApiTokenController::class, 'store'])->middleware('throttle:10,1')->name('api-tokens.store');
    Route::delete('/profile/api-tokens/{apiToken}', [ApiTokenController::class, 'destroy'])->name('api-tokens.destroy');

    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('/notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
    Route::post('/notifications/{id}/read', [NotificationController::class, 'read'])->whereUuid('id')->name('notifications.read');

    Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
    Route::get('/reports/export', [ReportController::class, 'export'])->middleware('throttle:20,1')->name('reports.export');

    Route::get('/audit-logs', [AuditLogController::class, 'index'])->name('audit-logs.index');

    Route::get('/webhooks', [WebhookController::class, 'index'])->name('webhooks.index');
    Route::get('/webhooks/create', [WebhookController::class, 'create'])->name('webhooks.create');
    Route::post('/webhooks', [WebhookController::class, 'store'])->name('webhooks.store');
    Route::get('/webhooks/{webhook}', [WebhookController::class, 'show'])->name('webhooks.show');
    Route::get('/webhooks/{webhook}/edit', [WebhookController::class, 'edit'])->name('webhooks.edit');
    Route::put('/webhooks/{webhook}', [WebhookController::class, 'update'])->name('webhooks.update');
    Route::delete('/webhooks/{webhook}', [WebhookController::class, 'destroy'])->name('webhooks.destroy');
    Route::post('/webhooks/{webhook}/secret', [WebhookController::class, 'rotateSecret'])->name('webhooks.secret');
    Route::post('/webhooks/{webhook}/test', [WebhookController::class, 'test'])->name('webhooks.test');
    Route::post('/webhooks/{webhook}/deliveries/{delivery}/redeliver', [WebhookController::class, 'redeliver'])->name('webhooks.redeliver');

    Route::get('/search', SearchController::class)->middleware('throttle:60,1')->name('search');

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

    Route::get('/domains', [DomainController::class, 'index'])->name('domains.index');
    Route::get('/domains/create', [DomainController::class, 'create'])->name('domains.create');
    Route::post('/domains', [DomainController::class, 'store'])->name('domains.store');
    Route::get('/domains/{domain}', [DomainController::class, 'show'])->withTrashed()->name('domains.show');
    Route::get('/domains/{domain}/edit', [DomainController::class, 'edit'])->name('domains.edit');
    Route::put('/domains/{domain}', [DomainController::class, 'update'])->name('domains.update');
    Route::delete('/domains/{domain}', [DomainController::class, 'destroy'])->name('domains.destroy');
    Route::post('/domains/{domain}/pause', [DomainController::class, 'pause'])->name('domains.pause');
    Route::post('/domains/{domain}/resume', [DomainController::class, 'resume'])->name('domains.resume');
    Route::post('/domains/{domain}/check', [DomainController::class, 'checkNow'])->name('domains.check');
    Route::post('/domains/{domain}/restore', [DomainController::class, 'restore'])->withTrashed()->name('domains.restore');
    Route::delete('/domains/{domain}/permanent', [DomainController::class, 'forceDestroy'])->withTrashed()->name('domains.force-destroy');

    Route::get('/incidents', [IncidentController::class, 'index'])->name('incidents.index');
    Route::get('/incidents/create', [IncidentController::class, 'create'])->name('incidents.create');
    Route::post('/incidents', [IncidentController::class, 'store'])->name('incidents.store');
    Route::get('/incidents/{incident}', [IncidentController::class, 'show'])->name('incidents.show');
    Route::post('/incidents/{incident}/status', [IncidentController::class, 'transition'])->name('incidents.transition');
    Route::post('/incidents/{incident}/assign', [IncidentController::class, 'assign'])->name('incidents.assign');
    Route::post('/incidents/{incident}/severity', [IncidentController::class, 'severity'])->name('incidents.severity');
    Route::post('/incidents/{incident}/comments', [IncidentController::class, 'comment'])
        ->middleware('throttle:30,1')
        ->name('incidents.comment');

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
