<?php

namespace Usjnet\Sso\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use InvalidArgumentException;
use Usjnet\Sso\Exceptions\NoLocalUserForSsoException;
use Usjnet\Sso\SsoAuthService;
use Usjnet\Sso\Support\AuthenticatesSsoRequest;
use Usjnet\Sso\Support\HandlesSsoLogout;

class EnsureSsoWebAuthenticated
{
    use AuthenticatesSsoRequest;
    use HandlesSsoLogout;

    public function __construct(private readonly SsoAuthService $ssoAuthService)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->allowsWithoutAccessCookie($request)) {
            return $next($request);
        }

        if ($this->isWebSsoExemptByConfiguredPaths($request)) {
            return $next($request);
        }

        $cookieName = (string) config('usjnet-sso.access_token_cookie', 'sso_access_token');
        $token = $request->cookie($cookieName);

        if (! is_string($token) || trim($token) === '') {
            return $this->redirectToSso($request);
        }

        try {
            $user = $this->ssoAuthService->validateAccessToken($token);
        } catch (Throwable) {
            $this->performSsoLogoutSafely($request, $this->ssoAuthService);
            $this->purgeLocalAuthentication($request);

            return $this->clearAuthCookies($this->redirectAfterInvalidSsoWebToken($request, true));
        }

        try {
            $this->authenticateSsoRequest($request, $user);
        } catch (NoLocalUserForSsoException $e) {
            Auth::forgetGuards();
            $home = rtrim((string) config('usjnet-sso.frontend_home_url', ''), '/');
            if ($home === '') {
                return response($e->getMessage(), 403);
            }

            return redirect()->away($home.'?'.http_build_query([
                'login_error' => 'no_local_account',
                'login_error_description' => $e->getMessage(),
            ]));
        } catch (InvalidArgumentException $e) {
            Auth::forgetGuards();

            return response($e->getMessage(), 500);
        }

        return $next($request);
    }

    private function redirectToSso(Request $request, bool $forceLogin = false): Response
    {
        $request->session()->put('url.intended', $request->fullUrl());

        $state = (string) Str::uuid();
        $query = ['state' => $state];
        if ($forceLogin) {
            $query['prompt_login'] = 1;
        }

        return redirect('/sso/spa/redirect?'.http_build_query($query));
    }

    /**
     * When the SSO access token is no longer valid: OAuth re-login, or SPA login URL (see USJNET_SSO_INVALID_SESSION_REDIRECT).
     */
    private function redirectAfterInvalidSsoWebToken(Request $request, bool $forceLogin = false): Response
    {
        if (config('usjnet-sso.invalid_sso_web_session_redirect') === 'frontend') {
            $home = rtrim((string) config('usjnet-sso.frontend_home_url', ''), '/');
            if ($home !== '') {
                return redirect()->away($home.'?'.http_build_query([
                    'login_error' => 'session_expired',
                    'login_error_description' => 'Your SSO session is no longer valid. Please sign in again.',
                ]));
            }
        }

        return $this->redirectToSso($request, $forceLogin);
    }

    /**
     * OAuth start/callback must never be gated by the access-token cookie, or we redirect to ourselves forever
     * when `sso.web` is part of the global `web` middleware group.
     */
    private function allowsWithoutAccessCookie(Request $request): bool
    {
        if ($request->routeIs('usjnet.sso.spa.redirect', 'usjnet.sso.spa.callback')) {
            return true;
        }

        $path = trim((string) $request->path(), '/');
        $paths = config('usjnet-sso.web_sso_public_paths', []);
        if (! is_array($paths)) {
            return false;
        }
        foreach ($paths as $allowed) {
            $allowed = trim((string) $allowed, '/');
            if ($allowed !== '' && $path === $allowed) {
                return true;
            }
        }

        return false;
    }

    /**
     * Local session areas: explicit prefixes and/or paths derived from configured local login URLs (see web_sso_local_login_paths).
     */
    private function isWebSsoExemptByConfiguredPaths(Request $request): bool
    {
        $path = trim((string) $request->path(), '/');

        $loginPaths = config('usjnet-sso.web_sso_local_login_paths', []);
        if (is_array($loginPaths)) {
            foreach ($loginPaths as $loginPath) {
                $loginPath = trim(str_replace('\\', '/', (string) $loginPath), '/');
                if ($loginPath === '') {
                    continue;
                }
                if ($path === $loginPath) {
                    return true;
                }
                $parent = dirname($loginPath);
                if ($parent !== '.' && $parent !== '/' && $parent !== '') {
                    $parent = trim(str_replace('\\', '/', $parent), '/');
                    if ($parent !== '' && ($path === $parent || str_starts_with($path, $parent.'/'))) {
                        return true;
                    }
                }
            }
        }

        $prefixes = config('usjnet-sso.web_sso_exempt_path_prefixes', []);
        if (! is_array($prefixes)) {
            return false;
        }
        foreach ($prefixes as $prefix) {
            $prefix = trim((string) $prefix, '/');
            if ($prefix === '') {
                continue;
            }
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                return true;
            }
        }

        return false;
    }
}

