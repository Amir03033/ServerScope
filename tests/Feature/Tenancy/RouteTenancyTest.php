<?php

namespace Tests\Feature\Tenancy;

use App\Http\Middleware\AuthenticateAgent;
use App\Http\Middleware\AuthenticateApiToken;
use App\Http\Middleware\ResolveTenant;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Guards against a new page that forgets the tenant and would therefore
 * show the data of every team: every route must either set a tenant or
 * be listed here as deliberately public.
 */
class RouteTenancyTest extends TestCase
{
    /**
     * Deliberately public routes ("METHODS uri"): they show no team data,
     * or determine the team themselves (public status pages).
     */
    private const PUBLIC_ROUTES = [
        'GET|HEAD up',
        'GET|HEAD|POST|PUT|PATCH|DELETE|OPTIONS|QUERY /',
        'GET|HEAD login',
        'POST login',
        'GET|HEAD register',
        'POST register',
        'GET|HEAD forgot-password',
        'POST forgot-password',
        'GET|HEAD reset-password/{token}',
        'POST reset-password',
        'GET|HEAD two-factor-challenge',
        'POST two-factor-challenge',
        'POST two-factor-challenge/cancel',
        // Public status pages render as the page's own team.
        'GET|HEAD status/{slug}.json',
        'GET|HEAD status/{slug}',
    ];

    /**
     * Routes registered by packages, not by ServerScope (Livewire is
     * installed but unused; storage/ serves the local disk). They read no
     * team data.
     */
    private const FRAMEWORK_URI_PREFIXES = ['livewire-', 'storage/'];

    /** Middleware that authenticates and sets the tenant itself. */
    private const SELF_TENANTING = [AuthenticateApiToken::class, AuthenticateAgent::class];

    public function test_every_authenticated_route_sets_the_tenant(): void
    {
        $missing = [];

        foreach (Route::getRoutes() as $route) {
            if ($this->has($route, ['auth', Authenticate::class]) && ! $this->has($route, ['tenant', ResolveTenant::class])) {
                $missing[] = $this->key($route);
            }
        }

        $this->assertSame([], $missing, "Routes with 'auth' but without 'tenant' would show the data of every team.");
    }

    public function test_every_route_without_login_is_deliberately_public_or_sets_its_own_tenant(): void
    {
        $unexpected = [];

        foreach (Route::getRoutes() as $route) {
            if ($this->has($route, ['auth', Authenticate::class]) || $this->has($route, self::SELF_TENANTING)) {
                continue;
            }

            if (in_array($this->key($route), self::PUBLIC_ROUTES, true) || $this->isFramework($route)) {
                continue;
            }

            $unexpected[] = $this->key($route);
        }

        $this->assertSame([], $unexpected, 'New routes without login: add auth + tenant, or list them in PUBLIC_ROUTES if they are deliberately public.');
    }

    public function test_the_public_list_only_names_existing_routes(): void
    {
        $existing = collect(Route::getRoutes()->getRoutes())->map(fn (RoutingRoute $route) => $this->key($route))->all();

        $this->assertSame([], array_values(array_diff(self::PUBLIC_ROUTES, $existing)), 'Remove routes that no longer exist from PUBLIC_ROUTES.');
    }

    private function key(RoutingRoute $route): string
    {
        return implode('|', $route->methods()).' '.$route->uri();
    }

    /** @param  array<int, string>  $names  aliases or class names */
    private function has(RoutingRoute $route, array $names): bool
    {
        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && in_array(explode(':', $middleware)[0], $names, true)) {
                return true;
            }
        }

        return false;
    }

    private function isFramework(RoutingRoute $route): bool
    {
        foreach (self::FRAMEWORK_URI_PREFIXES as $prefix) {
            if (str_starts_with($route->uri(), $prefix)) {
                return true;
            }
        }

        return false;
    }
}
