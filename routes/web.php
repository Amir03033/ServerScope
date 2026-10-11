
<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ServerRegistrationController;

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

Route::middleware('auth')->group(function () {
    Route::view('/dashboard', 'dashboard')
        ->name('dashboard');

    Route::post('/logout', [
        AuthenticatedSessionController::class,
        'destroy',
    ])->name('logout');

    Route::post('/servers/register', [
        ServerRegistrationController::class,
        'store',
    ])->name('servers.register');
});
