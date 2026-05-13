<?php

namespace Usjnet\Sso\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use InvalidArgumentException;
use Usjnet\Sso\Exceptions\NoLocalUserForSsoException;
use Usjnet\Sso\Http\Middleware\Concerns\BypassesSsoWhenLocalLoginActive;
use Usjnet\Sso\Http\Middleware\Concerns\RedirectsInvalidSsoWebSession;
use Usjnet\Sso\Http\Middleware\Concerns\SkipsSsoWebCookieGate;
use Usjnet\Sso\SsoAuthService;
use Usjnet\Sso\Support\AuthenticatesSsoRequest;
use Usjnet\Sso\Support\HandlesSsoLogout;

class EnsureSsoWebAuthenticated
{
    use AuthenticatesSsoRequest;
    use BypassesSsoWhenLocalLoginActive;
    use HandlesSsoLogout;
    use RedirectsInvalidSsoWebSession;
    use SkipsSsoWebCookieGate;

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

        if ($this->shouldBypassAllSsoChecks($request)) {
            return $next($request);
        }

        $cookieName = (string) config('usjnet-sso.access_token_cookie', 'sso_access_token');
        $token = $request->cookie($cookieName);

        if (! is_string($token) || trim($token) === '') {
            return $this->redirectToSso($request);
        }

        try {
            $user = $this->ssoAuthService->validateAccessTokenForHttpRequest($request, $token);
        } catch (Throwable) {
            $this->performSsoLogoutSafely($request, $this->ssoAuthService);
            $this->purgeLocalAuthentication($request);

            return $this->clearAuthCookies($this->respondInvalidSsoWebToken($request, true));
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
}
