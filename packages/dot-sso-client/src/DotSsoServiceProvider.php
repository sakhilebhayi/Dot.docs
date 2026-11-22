<?php

namespace Dot\SsoClient;

use Dot\SsoClient\Http\Middleware\EnforceSsoRevocation;
use Dot\SsoClient\Http\Middleware\InterceptLocalAuth;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;

/**
 * Dot ecosystem SSO, relying-party side (R-20 section 16, R-54 phase 2). The platform keeps its own domain, session,
 * users table and authorisation; only the question "who is this person?" is answered by InfoDot, through the standard
 * OIDC authorization-code flow with PKCE.
 *
 * With DOTSSO_ENABLED false (the default) every route answers 404 and the two middleware pass straight through, so the
 * package can be merged and deployed to a platform with no change in behaviour. The flag is read per request, which is
 * also what makes the rollback instant.
 */
class DotSsoServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/dotsso.php', 'dotsso');
    }

    public function boot(): void
    {
        $this->publishes([__DIR__.'/../config/dotsso.php' => config_path('dotsso.php')], 'dotsso-config');
        $this->publishes([__DIR__.'/../resources/views' => resource_path('views/vendor/dotsso')], 'dotsso-views');

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'dotsso');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/sso.php');

        /** @var Router $router */
        $router = $this->app->make(Router::class);

        $router->prependMiddlewareToGroup('web', InterceptLocalAuth::class);
        $router->pushMiddlewareToGroup('web', EnforceSsoRevocation::class);
    }
}
