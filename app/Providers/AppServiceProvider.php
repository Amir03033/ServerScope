<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Models\Alert;
use App\Models\User;
use App\Monitoring\Dns\DnsClient;
use App\Monitoring\Dns\Resolver;
use App\Monitoring\Net\IpPolicy;
use App\Monitoring\Net\SafeHttpClient;
use App\Monitoring\Net\TargetGuard;
use App\Monitoring\Net\TlsInspector;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(Resolver::class, fn () => new DnsClient(
            config('serverscope.monitoring.nameservers', []),
            (float) config('serverscope.monitoring.dns_timeout', 3),
        ));

        $this->app->singleton(IpPolicy::class, fn () => new IpPolicy(
            (bool) config('serverscope.monitoring.allow_private_targets', false),
            config('serverscope.monitoring.allowed_cidrs', []),
        ));

        $this->app->bind(TargetGuard::class, fn ($app) => new TargetGuard(
            $app->make(Resolver::class),
            $app->make(IpPolicy::class),
            config('serverscope.monitoring.allowed_ports', [80, 443]),
        ));

        $this->app->bind(SafeHttpClient::class, fn ($app) => new SafeHttpClient(
            $app->make(TargetGuard::class),
            (int) config('serverscope.monitoring.max_redirects', 5),
            (int) config('serverscope.monitoring.max_response_bytes', 1048576),
            (int) config('serverscope.monitoring.requests_per_ip_per_minute', 30),
            (string) config('serverscope.monitoring.user_agent'),
        ));

        $this->app->bind(TlsInspector::class, fn () => new TlsInspector(
            config('serverscope.monitoring.ca_file'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::define('admin', fn (User $user): bool => (bool) $user->is_admin);

        View::composer(
            'components.layouts.app',
            function ($view) {
                $view->with(
                    'sidebarActiveAlerts',
                    Alert::query()->where('status', 'active')->count()
                );
            }
        );
    }
}
