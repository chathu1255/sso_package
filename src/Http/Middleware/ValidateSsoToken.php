<?php

namespace Usjnet\Sso\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use InvalidArgumentException;
use Usjnet\Sso\Exceptions\NoLocalUserForSsoException;
use Usjnet\Sso\Http\Middleware\Concerns\SkipsSsoWebCookieGate;
use Usjnet\Sso\SsoAuthService;
use Usjnet\Sso\Support\AuthenticatesSsoRequest;
use Usjnet\Sso\Support\HandlesSsoLogout;
use Usjnet\Sso\Support\LocalLoginZone;

class ValidateSsoToken
{
    use AuthenticatesSsoRequest;
    use HandlesSsoLogout;
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
        $token = $request->bearerToken() ?? $request->cookie($cookieName);

        if (! $token) {
            return $this->clearAuthCookies(response()->json(['message' => 'Bearer token is required.'], 401));
        }

        try {
            $user = $this->ssoAuthService->validateAccessTokenForHttpRequest($request, $token);
        } catch (Throwable) {
            if (LocalLoginZone::hasActiveLocalGuardSession($request)) {
                $this->performSsoLogoutSafely($request, $this->ssoAuthService);

                return $this->clearAuthCookies($next($request));
            }

            $this->performSsoLogoutSafely($request, $this->ssoAuthService);
            $this->purgeLocalAuthentication($request);

            return $this->clearAuthCookies(response()->json([
                'message' => 'Session expired or invalid. Please login again.',
            ], 401));
        }

        try {
            $this->authenticateSsoRequest($request, $user);
        } catch (NoLocalUserForSsoException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }

        return $next($request);
    }
}
