<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureUserIsActive;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Auth\Middleware\RedirectIfAuthenticated;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\TestCase;

/**
 * Guards against an unprotected page being merged: every route must require a
 * signed-in, active user unless it is on the short, reviewed list below.
 */
class RouteProtectionTest extends TestCase
{
    /** uri => middleware the route must have instead of auth + active. */
    private const EXCEPTIONS = [
        '/' => [],                                   // redirect to /dashboard (which is protected)
        'up' => [],                                  // health check used by deployments
        'sanctum/csrf-cookie' => [],                 // framework endpoint, returns only a cookie
        'login' => [RedirectIfAuthenticated::class],
        // Signed-in but possibly forced to change password, so not behind "active":
        'change-password' => [Authenticate::class],
        'password' => [Authenticate::class],
        'logout' => [Authenticate::class],
        // Laravel local-disk file serving; refuses unsigned requests while the disk is private (asserted below).
        'storage/{path}' => [],
    ];

    public function test_every_route_requires_an_active_signed_in_user_unless_reviewed(): void
    {
        $router = app('router');
        $unprotected = [];

        /** @var Route $route */
        foreach (RouteFacade::getRoutes() as $route) {
            $middleware = $router->gatherRouteMiddleware($route);
            $uri = $route->uri();

            if (array_key_exists($uri, self::EXCEPTIONS)) {
                foreach (self::EXCEPTIONS[$uri] as $required) {
                    $this->assertContains($required, $middleware, "Route [{$uri}] lost its {$required} middleware.");
                }

                continue;
            }

            if (! in_array(Authenticate::class, $middleware, true) || ! in_array(EnsureUserIsActive::class, $middleware, true)) {
                $unprotected[] = implode('|', $route->methods()).' '.$uri;
            }
        }

        $this->assertSame([], $unprotected, "Routes without auth + active middleware:\n".implode("\n", $unprotected));
    }

    public function test_private_file_serving_requires_a_signature(): void
    {
        $this->assertNotSame('public', config('filesystems.disks.local.visibility', 'private'));

        $this->get('/storage/anything.pdf')->assertForbidden();
    }
}
