<?php

namespace Usjnet\Sso;

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Usjnet\Sso\Console\DoctorUsjnetSsoCommand;
use Usjnet\Sso\Console\InstallUsjnetSsoCommand;
use Usjnet\Sso\Http\Middleware\EnsureHybridWebAuthenticated;
use Usjnet\Sso\Http\Middleware\EnsureSsoWebAuthenticated;
use Usjnet\Sso\Http\Middleware\ValidateSsoToken;
use Usjnet\Sso\Http\Middleware\VerifySsoAccessTokenLive;
use Usjnet\Sso\Support\LocalLoginZone;

class UsjnetSsoServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/usjnet-sso.php', 'usjnet-sso');

        $this->app->singleton(SsoAuthService::class);
    }

    public function boot(): void
    {
        $this->applyLocalLoginZoneConfig();

        /** @var Router $router */
        $router = $this->app['router'];
        $router->aliasMiddleware('sso.token', ValidateSsoToken::class);
        $router->aliasMiddleware('sso.web', EnsureSsoWebAuthenticated::class);
        $router->aliasMiddleware('sso.hybrid', EnsureHybridWebAuthenticated::class);
        $router->aliasMiddleware('sso.web.live', VerifySsoAccessTokenLive::class);

        if (config('usjnet-sso.verify_sso_access_token_on_web_middleware_group') === true) {
            $router->pushMiddlewareToGroup('web', VerifySsoAccessTokenLive::class);
        }

        $webAlias = config('usjnet-sso.web_middleware_alias');
        if (is_string($webAlias) && $webAlias !== '' && $webAlias !== 'sso.web' && preg_match('/^[a-zA-Z0-9_-]+$/', $webAlias) === 1) {
            $router->aliasMiddleware($webAlias, $this->resolveWebAliasMiddlewareClass());
        }

        Route::middleware('web')->group(function (): void {
            require __DIR__.'/../routes/sso-web.php';
        });

        $apiMiddleware = config('usjnet-sso.api_route_middleware', ['api']);
        $apiPrefix = (string) config('usjnet-sso.api_route_prefix', 'api');

        Route::middleware($apiMiddleware)->prefix($apiPrefix)->group(function (): void {
            require __DIR__.'/../routes/sso-api.php';
        });

        $this->publishes([
            __DIR__.'/../config/usjnet-sso.php' => config_path('usjnet-sso.php'),
        ], 'usjnet-sso-config');

        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallUsjnetSsoCommand::class,
                DoctorUsjnetSsoCommand::class,
            ]);
        }
    }

    /**
     * Defaults: admin/login + admin guard when env keys are unset (local login works without extra .env).
     * Set USJNET_SSO_WEB_LOCAL_LOGIN_PATHS= (empty) to disable the local-login zone.
     */
    private function applyLocalLoginZoneConfig(): void
    {
        config([
            'usjnet-sso.web_sso_local_login_paths' => LocalLoginZone::loginPaths(),
            'usjnet-sso.web_sso_exempt_path_prefixes' => LocalLoginZone::guestOnlyExemptPrefixes(),
            'usjnet-sso.web_sso_local_login_guards' => LocalLoginZone::bypassGuards(),
        ]);

        LocalLoginZone::registerMissingGuards();
    }

    private function resolveWebAliasMiddlewareClass(): string
    {
        return config('usjnet-sso.web_middleware_mode') === 'hybrid'
            ? EnsureHybridWebAuthenticated::class
            : EnsureSsoWebAuthenticated::class;
    }
}
