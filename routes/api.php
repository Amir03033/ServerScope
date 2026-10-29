<?php

use App\Http\Controllers\Api\MetricSampleController;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\MonitoringApiController;
use App\Http\Middleware\IdempotentRequests;
use App\Http\Middleware\AuthenticateAgent;
use App\Http\Middleware\AuthenticateApiToken;
use Illuminate\Support\Facades\Route;

Route::post('/agent/metrics', [
    MetricSampleController::class,
    'store',
])
    ->middleware([
        AuthenticateAgent::class,
        'throttle:60,1',
    ])
    ->name('agent.metrics.store');

// One-command install (install.sh): exchange a one-time enrollment token
// for an agent credential, check the first sample, revoke on uninstall.
Route::post('/agent/enroll', [\App\Http\Controllers\Api\AgentEnrollmentController::class, 'enroll'])
    ->middleware('throttle:agent-enroll')
    ->name('agent.enroll');
Route::get('/agent/self', [\App\Http\Controllers\Api\AgentEnrollmentController::class, 'self'])
    ->middleware([AuthenticateAgent::class, 'throttle:agent-self'])
    ->name('agent.self');
Route::post('/agent/revoke', [\App\Http\Controllers\Api\AgentEnrollmentController::class, 'revoke'])
    ->middleware([AuthenticateAgent::class, 'throttle:agent-revoke'])
    ->name('agent.revoke');

// The updater (serverscope-updater): asks for an update request and
// reports the result. Only its own server; own rate limit per credential.
Route::get('/agent/update', [\App\Http\Controllers\Api\AgentUpdateController::class, 'check'])
    ->middleware([AuthenticateAgent::class, 'throttle:agent-update'])
    ->name('agent.update.check');
Route::post('/agent/update/report', [\App\Http\Controllers\Api\AgentUpdateController::class, 'report'])
    ->middleware([AuthenticateAgent::class, 'throttle:agent-update'])
    ->name('agent.update.report');

// Public REST API, authenticated with personal API tokens.
Route::prefix('v1')->name('api.v1.')->middleware('throttle:api-token')->group(function () {
    Route::middleware(AuthenticateApiToken::class.':read')->group(function () {
        Route::get('/servers', [ApiController::class, 'servers'])->name('servers.index');
        Route::get('/servers/{server}', [ApiController::class, 'server'])->name('servers.show');
        Route::get('/servers/{server}/metrics', [ApiController::class, 'metrics'])->name('servers.metrics');
        Route::get('/domains', [ApiController::class, 'domains'])->name('domains.index');
        Route::get('/domains/{domain}', [ApiController::class, 'domain'])->name('domains.show');
        Route::get('/domains/{domain}/checks', [ApiController::class, 'domainChecks'])->name('domains.checks');
        Route::get('/incidents', [ApiController::class, 'incidents'])->name('incidents.index');
        Route::get('/incidents/{incident}', [ApiController::class, 'incident'])->name('incidents.show');

        Route::get('/domains/{domain}/pages', [MonitoringApiController::class, 'pages'])->name('pages.index');
        Route::get('/domains/{domain}/discovery-runs', [MonitoringApiController::class, 'discoveryRuns'])->name('discovery.index');
        Route::get('/pages/{page}', [MonitoringApiController::class, 'page'])->name('pages.show');
        Route::get('/pages/{page}/checks', [MonitoringApiController::class, 'pageChecks'])->name('pages.checks');
        Route::get('/dependencies', [MonitoringApiController::class, 'dependencies'])->name('dependencies.index');
        Route::get('/servers/{server}/services', [MonitoringApiController::class, 'services'])->name('servers.services');
        Route::get('/predictions', [MonitoringApiController::class, 'predictions'])->name('predictions.index');
    });

    Route::middleware([AuthenticateApiToken::class.':write', IdempotentRequests::class])->group(function () {
        Route::post('/domains/{domain}/pages', [MonitoringApiController::class, 'storePages'])->name('pages.store');
        Route::put('/pages/{page}', [MonitoringApiController::class, 'updatePage'])->name('pages.update');
        Route::delete('/pages/{page}', [MonitoringApiController::class, 'destroyPage'])->name('pages.destroy');
        Route::post('/pages/{page}/{action}', [MonitoringApiController::class, 'pageState'])->whereIn('action', ['pause', 'resume', 'approve', 'reject'])->name('pages.state');
        Route::post('/domains/{domain}/discovery', [MonitoringApiController::class, 'startDiscovery'])->name('discovery.start');
        Route::post('/dependencies', [MonitoringApiController::class, 'storeDependency'])->name('dependencies.store');
        Route::delete('/dependencies/{dependency}', [MonitoringApiController::class, 'destroyDependency'])->name('dependencies.destroy');
        Route::post('/incidents/{incident}/assign', [MonitoringApiController::class, 'assign'])->name('incidents.assign');

        Route::post('/incidents/{incident}/acknowledge', [ApiController::class, 'transition'])->defaults('status', 'acknowledged')->name('incidents.acknowledge');
        Route::post('/incidents/{incident}/investigate', [ApiController::class, 'transition'])->defaults('status', 'investigating')->name('incidents.investigate');
        Route::post('/incidents/{incident}/resolve', [ApiController::class, 'transition'])->defaults('status', 'resolved')->name('incidents.resolve');
        Route::post('/incidents/{incident}/comments', [ApiController::class, 'comment'])->name('incidents.comment');
    });
});
