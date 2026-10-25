<?php

use App\Http\Controllers\Api\MetricSampleController;
use App\Http\Controllers\Api\V1\ApiController;
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
    });

    Route::middleware(AuthenticateApiToken::class.':write')->group(function () {
        Route::post('/incidents/{incident}/acknowledge', [ApiController::class, 'transition'])->defaults('status', 'acknowledged')->name('incidents.acknowledge');
        Route::post('/incidents/{incident}/investigate', [ApiController::class, 'transition'])->defaults('status', 'investigating')->name('incidents.investigate');
        Route::post('/incidents/{incident}/resolve', [ApiController::class, 'transition'])->defaults('status', 'resolved')->name('incidents.resolve');
        Route::post('/incidents/{incident}/comments', [ApiController::class, 'comment'])->name('incidents.comment');
    });
});
