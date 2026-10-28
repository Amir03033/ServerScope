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
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(\App\Tenancy\TenantContext::class);

        $this->app->singleton(Resolver::class, fn () => new DnsClient(
            config('serverscope.monitoring.nameservers', []),
            (float) config('serverscope.monitoring.dns_timeout', 3),
        ));

        $this->app->singleton(\App\Monitoring\Dns\ResolverFactory::class, fn () => new \App\Monitoring\Dns\ResolverFactory(
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

        $this->app->bind(\App\Services\Domains\RdapClient::class, fn ($app) => new \App\Services\Domains\RdapClient(
            $app->make(SafeHttpClient::class),
            (string) config('serverscope.monitoring.rdap_bootstrap_url'),
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
        // Administrator of the current team (owner or admin role). The
        // users.is_admin column is only a mirror and grants nothing.
        Gate::define('admin', fn (User $user): bool => \App\Tenancy\Tenant::check() && $user->isAdminOf(\App\Tenancy\Tenant::id()));

        // Owner of the ServerScope platform: platform-wide system health.
        // Gives no access to data or configuration of any team.
        Gate::define('platform', fn (User $user): bool => (bool) $user->is_platform_admin);

        // Jobs always run without a tenant, also on the sync queue inside a
        // request, exactly like in a queue worker; the request's tenant is
        // restored afterwards.
        $suspended = [];
        \Illuminate\Support\Facades\Queue::before(function () use (&$suspended): void {
            $suspended[] = \App\Tenancy\Tenant::id();
            \App\Tenancy\Tenant::forget();
        });
        $resume = function () use (&$suspended): void {
            if ($suspended !== []) {
                \App\Tenancy\Tenant::set(array_pop($suspended));
            }
        };
        \Illuminate\Support\Facades\Queue::after($resume);
        \Illuminate\Support\Facades\Queue::exceptionOccurred($resume);

        // Per token (or per IP for unauthenticated calls).
        \Illuminate\Support\Facades\RateLimiter::for('api-token', fn (\Illuminate\Http\Request $request) => \Illuminate\Cache\RateLimiting\Limit::perMinute((int) config('serverscope.api.requests_per_minute', 120))
            ->by('api:'.hash('sha256', (string) ($request->bearerToken() ?? $request->ip())))
            ->response(fn () => response()->json(['message' => 'Te veel verzoeken.'], 429)));

        // Enrollment: strict per address, plus a ceiling for the whole
        // platform against token guessing from many addresses.
        \Illuminate\Support\Facades\RateLimiter::for('agent-enroll', fn (\Illuminate\Http\Request $request) => [
            \Illuminate\Cache\RateLimiting\Limit::perMinute(max(1, (int) config('serverscope.enrollment.attempts_per_minute', 10)))
                ->by('enroll:'.$request->ip())
                ->response(fn () => response()->json(['message' => 'Te veel koppelpogingen. Probeer het over een minuut opnieuw.'], 429)),
            \Illuminate\Cache\RateLimiting\Limit::perMinute(300)->by('enroll:all')
                ->response(fn () => response()->json(['message' => 'Te veel koppelpogingen. Probeer het over een minuut opnieuw.'], 429)),
        ]);

        \Illuminate\Auth\Notifications\ResetPassword::toMailUsing(fn (User $user, string $token) => (new \Illuminate\Notifications\Messages\MailMessage)
            ->subject('[ServerScope] Wachtwoord opnieuw instellen')
            ->line('Je ontvangt deze e-mail omdat er een nieuw wachtwoord is aangevraagd voor je ServerScope-account.')
            ->action('Nieuw wachtwoord instellen', route('password.reset', ['token' => $token, 'email' => $user->email]))
            ->line('Deze link is 60 minuten geldig. Heb je dit niet aangevraagd? Dan hoef je niets te doen.'));

        \Illuminate\Support\Facades\Event::subscribe(\App\Listeners\IncidentNotifier::class);

        // Short, stable type names in polymorphic columns.
        Relation::morphMap([
            'server' => \App\Models\Server::class,
            'domain' => \App\Models\Domain::class,
            'incident' => \App\Models\Incident::class,
            'alert_rule' => \App\Models\AlertRule::class,
            'user' => User::class,
            'resource_dependency' => \App\Models\ResourceDependency::class,
            'page' => \App\Models\MonitoredPage::class,
            'service' => \App\Models\ServiceCheck::class,
        ]);

        View::composer(
            'components.layouts.app',
            function ($view) {
                $view->with(
                    'sidebarActiveAlerts',
                    Alert::query()->where('status', 'active')->count()
                );
                $view->with(
                    'sidebarUnreadNotifications',
                    auth()->user()?->unreadNotifications()->count() ?? 0
                );
                $view->with(
                    'sidebarOpenIncidents',
                    \App\Models\Incident::query()->active()->count()
                );
            }
        );
    }
}
