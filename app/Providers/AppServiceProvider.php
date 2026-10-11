<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Models\Alert;
use Illuminate\Support\Facades\View;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        View::composer(
            ['dashboard', 'alerts.*', 'alert-rules.*', 'servers.*'],
            function ($view) {
                $view->with(
                    'sidebarActiveAlerts',
                    Alert::query()->where('status', 'active')->count()
                );
            }
        );
    }
}
