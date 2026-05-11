<?php

namespace Usjnet\Sso\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Route;

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

    private function routePathExists(string $path): bool
    {
        foreach (Route::getRoutes() as $route) {
            if (trim($route->uri(), '/') === trim($path, '/')) {
                return true;
            }
        }

        return false;
    }
}

