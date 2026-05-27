<?php

namespace Usjnet\Sso\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use InvalidArgumentException;
use Usjnet\Sso\Exceptions\NoLocalUserForSsoException;
use Usjnet\Sso\Http\Middleware\Concerns\RedirectsInvalidSsoWebSession;
use Usjnet\Sso\Http\Middleware\Concerns\SkipsSsoWebCookieGate;
use Usjnet\Sso\SsoAuthService;
use Usjnet\Sso\Support\AuthenticatesSsoRequest;
use Usjnet\Sso\Support\HandlesSsoLogout;

/**
 * Re-validates an SSO access cookie with the IdP on every web request (when appended to the `web` group).
 *
 * Unlike {@see EnsureSsoWebAuthenticated}, this middleware does nothing when no SSO cookie is present
 * (guest pages stay guest). When a cookie exists but the token is invalid, it clears SSO cookies;
 * local admin/session login is preserved (stale SSO cookies must not log out admin).
 */
class VerifySsoAccessTokenLive
{
    use AuthenticatesSsoRequest;
    use HandlesSsoLogout;
    use RedirectsInvalidSsoWebSession;
    use SkipsSsoWebCookieGate;

    public function __construct(private readonly SsoAuthService $ssoAuthService)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->shouldSkipSsoWebMiddleware($request)) {
            return $next($request);
        }

        $cookieName = (string) config('usjnet-sso.access_token_cookie', 'sso_access_token');
        $token = $request->cookie($cookieName);

        if (! is_string($token) || trim($token) === '') {
            return $next($request);
        }

        try {
            $user = $this->ssoAuthService->validateAccessTokenForHttpRequest($request, $token);
        } catch (Throwable) {
            return $this->handleInvalidSsoTokenForWeb(
                $request,
                $this->ssoAuthService,
                $this->respondInvalidSsoWebToken($request, true),
                static fn (): Response => $next($request)
            );
        }

        try {
            $this->authenticateSsoRequest($request, $user);
        } catch (NoLocalUserForSsoException $e) {
            Auth::forgetGuards();
            $home = rtrim((string) config('usjnet-sso.frontend_home_url', ''), '/');
            if ($home === '') {
                return $this->clearAuthCookies(response($e->getMessage(), 403));
            }

            return $this->clearAuthCookies(redirect()->away($home.'?'.http_build_query([
                'login_error' => 'no_local_account',
                'login_error_description' => $e->getMessage(),
            ])));
        } catch (InvalidArgumentException $e) {
            Auth::forgetGuards();

            return response($e->getMessage(), 500);
        }

        if ($request->hasSession()) {
            $request->session()->put('usjnet_sso.access_token', trim($token));
        }

        return $next($request);
    }
}
