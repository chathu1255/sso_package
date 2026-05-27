<?php

namespace Usjnet\Sso\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Usjnet\Sso\Http\Middleware\VerifySsoAccessTokenLive;
use Usjnet\Sso\Support\LocalLoginZone;

class DoctorUsjnetSsoCommand extends Command
{
    protected $signature = 'usjnet-sso:doctor';

    protected $description = 'Validate SSO package setup (env, routes, config, middleware)';

    private bool $hasFailures = false;

    public function handle(): int
    {
        $this->info('usjnet-sso doctor');
        $this->line('------------------');

        $appUrl = (string) config('app.url', '');
        $this->check('APP_URL configured', $appUrl !== '', $appUrl);

        $baseUrl = (string) config('usjnet-sso.base_url', '');
        $this->check('USJNET_SSO_BASE_URL configured', $baseUrl !== '', $baseUrl);

        $clientId = (string) config('usjnet-sso.client_id', '');
        $this->check('USJNET_SSO_CLIENT_ID configured', $clientId !== '', $clientId !== '' ? 'set' : '');

        $clientSecret = (string) config('usjnet-sso.client_secret', '');
        $this->check('USJNET_SSO_CLIENT_SECRET configured', $clientSecret !== '', $clientSecret !== '' ? 'set' : '');

        $redirectUri = (string) config('usjnet-sso.redirect_uri', '');
        $this->check('USJNET_SSO_REDIRECT_URI configured', $redirectUri !== '', $redirectUri);
        if ($redirectUri !== '' && $appUrl !== '') {
            $appHost = parse_url($appUrl, PHP_URL_HOST);
            $redirectHost = parse_url($redirectUri, PHP_URL_HOST);
            $this->check('Redirect URI host matches APP_URL host', $appHost === $redirectHost, "APP={$appHost} REDIRECT={$redirectHost}");
        }

        $frontendHome = (string) config('usjnet-sso.frontend_home_url', '');
        $this->check('USJNET_SSO_FRONTEND_HOME_URL configured', $frontendHome !== '', $frontendHome);
        $isCrossOriginFrontend = $this->originsDiffer($appUrl, $frontendHome);
        $browserLogoutUrl = trim((string) config('usjnet-sso.browser_logout_url', ''));

        $cookieSameSite = strtolower(trim((string) config('usjnet-sso.cookie_same_site', 'lax')));
        $cookieSecure = (bool) config('usjnet-sso.cookie_secure', false);
        $this->check('SSO cookie SameSite configured', in_array($cookieSameSite, ['lax', 'none', 'strict'], true), $cookieSameSite);
        if ($isCrossOriginFrontend) {
            if ($browserLogoutUrl !== '') {
                if ($cookieSameSite !== 'none') {
                    $this->line('<fg=yellow>[WARN] Cross-origin SPA cookie SameSite is not none</> -> current='.$cookieSameSite.'. Browser logout redirect is configured, so this is acceptable for HTTP/internal-IP setups that do not rely on cross-site auth cookies.');
                }
                if (! $cookieSecure) {
                    $this->line('<fg=yellow>[WARN] Cross-origin SPA secure cookies are disabled</> -> current='.var_export($cookieSecure, true).'. Browser logout redirect is configured, so this is acceptable for HTTP/internal-IP setups that do not rely on cross-site auth cookies.');
                }
            } else {
                $this->check(
                    'Cross-origin SPA: cookie SameSite must be none for credentials-based logout/login',
                    $cookieSameSite === 'none',
                    'current='.$cookieSameSite
                );
                $this->check(
                    'Cross-origin SPA: secure cookies should be enabled',
                    $cookieSecure,
                    'current='.var_export($cookieSecure, true)
                );
            }
        }

        $authMode = strtolower((string) config('usjnet-sso.auth_user_mode', 'sso'));
        if ($authMode === 'system') {
            $model = (string) config('usjnet-sso.system_user_model', '');
            $modelOk = $model !== '' && class_exists($model) && is_a($model, Authenticatable::class, true);
            $this->check('auth_user_mode=system: system_user_model exists and implements Authenticatable', $modelOk, $model !== '' ? $model : '(empty)');
        }

        $this->check('Route exists: /sso/spa/redirect', $this->routePathExists('sso/spa/redirect'));
        $this->check('Route exists: /sso/spa/callback', $this->routePathExists('sso/spa/callback'));
        $apiPrefix = trim((string) config('usjnet-sso.api_route_prefix', 'api'), '/');
        $this->check('Route exists: /'.$apiPrefix.'/auth/me', $this->routePathExists($apiPrefix.'/auth/me'));
        $this->check('Route exists: /'.$apiPrefix.'/auth/user_logout', $this->routePathExists($apiPrefix.'/auth/user_logout'));

        $accessCookie = (string) config('usjnet-sso.access_token_cookie', 'sso_access_token');
        $refreshCookie = (string) config('usjnet-sso.refresh_token_cookie', 'sso_refresh_token');
        $this->check('Access cookie name set', $accessCookie !== '', $accessCookie);
        $this->check('Refresh cookie name set', $refreshCookie !== '', $refreshCookie);

        $corsCreds = config('cors.supports_credentials');
        $this->check('CORS supports_credentials=true', $corsCreds === true, 'current='.var_export($corsCreds, true));

        $origins = config('cors.allowed_origins', []);
        $originsList = is_array($origins) ? implode(', ', $origins) : (string) $origins;
        $this->check('CORS allowed_origins configured', $originsList !== '', $originsList);

        $verifyLiveConfig = config('usjnet-sso.verify_sso_access_token_on_web_middleware_group') === true;
        /** @var Router $router */
        $router = app('router');
        $webGroup = $router->getMiddlewareGroups()['web'] ?? [];
        $liveRegistered = $this->webGroupContainsMiddleware($webGroup, VerifySsoAccessTokenLive::class);

        if ($verifyLiveConfig && ! $liveRegistered) {
            $this->line('<fg=red>[FAIL] VerifySsoAccessTokenLive is missing from the `web` middleware group</> -> run php artisan config:clear; ensure UsjnetSsoServiceProvider loads before routes.');
            $this->hasFailures = true;
        } elseif ($verifyLiveConfig && $liveRegistered) {
            $this->line('<fg=green>[PASS] Live SSO check (`sso.web.live`) is on the `web` stack</> — refresh/navigation re-validates the access cookie with the IdP.');
        } else {
            $this->line('<fg=yellow>[WARN] USJNET_SSO_VERIFY_LIVE_ON_WEB_GROUP is disabled</> — logging out in another app may not show here until token expiry; set true for automatic logout on next full page load.');
        }

        $localPathsList = implode(', ', LocalLoginZone::loginPaths());
        if ($localPathsList !== '') {
            $this->line('<fg=green>[PASS] Local login:</> entry paths='.$localPathsList.'; guards='.implode(', ', LocalLoginZone::bypassGuards()).' (when guard is logged in, SSO is skipped on ALL app paths).');
            $this->line('<fg=yellow>[INFO] Protected admin pages still need auth:admin. Login entry paths only skip SSO for guests.</>');
            $autoRegistered = LocalLoginZone::autoRegisteredGuards();
            if ($autoRegistered !== []) {
                $provider = (string) config('auth.guards.'.$autoRegistered[0].'.provider', 'users');
                $this->line('<fg=green>[PASS] Auto-registered local login guard(s):</> '.implode(', ', $autoRegistered).' (provider: '.$provider.', session driver). Add to config/auth.php to persist.');
            }

            foreach (LocalLoginZone::bypassGuards() as $guardName) {
                if (! array_key_exists($guardName, config('auth.guards', []))) {
                    $this->line('<fg=red>[FAIL] Local login guard "'.$guardName.'" is missing</> — enable USJNET_SSO_AUTO_REGISTER_LOCAL_LOGIN_GUARDS=true or add the guard to config/auth.php.');
                    $this->hasFailures = true;
                    $this->printMissingGuardHint($guardName);
                }
            }
            $exempt = (string) env('USJNET_SSO_WEB_EXEMPT_PREFIXES', '');
            if (preg_match('/(?:^|,)\s*admin\s*(?:,|$)/', $exempt) === 1) {
                $this->line('<fg=yellow>[WARN] USJNET_SSO_WEB_EXEMPT_PREFIXES includes "admin"</> — remove it unless you need guest-only URLs; use LOCAL_LOGIN_GUARDS after login instead.');
            }
        } else {
            $this->line('<fg=yellow>[WARN] Local login zone disabled</> (USJNET_SSO_WEB_LOCAL_LOGIN_PATHS empty).');
        }

        $webAlias = (string) config('usjnet-sso.web_middleware_alias', '');
        if ($webAlias === 'auth') {
            $this->line('<fg=yellow>[WARN] USJNET_SSO_WEB_MIDDLEWARE_ALIAS=auth</> — admin routes must use middleware("auth:admin"), never middleware("auth") alone.');
        }

        $this->newLine();
        if ($this->hasFailures) {
            $this->error('Doctor found configuration issues. Fix FAIL rows and re-run: php artisan usjnet-sso:doctor');
            return self::FAILURE;
        }

        $this->info('All critical checks passed.');
        return self::SUCCESS;
    }

    private function check(string $label, bool $pass, string $details = ''): void
    {
        $status = $pass ? 'PASS' : 'FAIL';
        $line = sprintf('[%s] %s', $status, $label);
        if ($details !== '') {
            $line .= ' -> '.$details;
        }

        if ($pass) {
            $this->line('<fg=green>'.$line.'</>');
            return;
        }

        $this->line('<fg=red>'.$line.'</>');
        $this->hasFailures = true;
    }

    private function printMissingGuardHint(string $guardName): void
    {
        $provider = config('auth.guards.web.provider', 'users');
        if (! is_string($provider) || $provider === '') {
            $provider = 'users';
        }

        $this->newLine();
        $this->line('<fg=cyan>Add to config/auth.php guards array (adjust provider if needed):</>');
        $this->line("    '{$guardName}' => ['driver' => 'session', 'provider' => '{$provider}'],");
        $this->line('<fg=cyan>Or set USJNET_SSO_LOCAL_LOGIN_GUARDS</> to a guard that already exists (e.g. web).');
        $this->newLine();
    }

    private function routePathExists(string $path): bool
    {
        foreach (Route::getRoutes() as $route) {
            if (trim($route->uri(), '/') === trim($path, '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, mixed>  $webGroup
     */
    private function webGroupContainsMiddleware(array $webGroup, string $middlewareClass): bool
    {
        foreach ($webGroup as $entry) {
            if ($entry === $middlewareClass) {
                return true;
            }
            if (is_array($entry)) {
                if ($this->webGroupContainsMiddleware($entry, $middlewareClass)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function originsDiffer(string $firstUrl, string $secondUrl): bool
    {
        if (trim($firstUrl) === '' || trim($secondUrl) === '') {
            return false;
        }

        return $this->normalizeOrigin($firstUrl) !== $this->normalizeOrigin($secondUrl);
    }

    private function normalizeOrigin(string $url): string
    {
        $parts = parse_url($url);
        if (! is_array($parts)) {
            return '';
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $port = isset($parts['port']) ? (string) $parts['port'] : '';

        if ($scheme === '' || $host === '') {
            return '';
        }

        return $scheme.'://'.$host.($port !== '' ? ':'.$port : '');
    }
}

