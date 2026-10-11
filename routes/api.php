<?php

use App\Http\Controllers\Api\MetricSampleController;
use App\Http\Middleware\AuthenticateAgent;
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
