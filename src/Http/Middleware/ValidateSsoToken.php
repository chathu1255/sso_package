<?php

namespace Usjnet\Sso\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use InvalidArgumentException;
use Usjnet\Sso\Exceptions\NoLocalUserForSsoException;
use Usjnet\Sso\SsoAuthService;
use Usjnet\Sso\Support\AuthenticatesSsoRequest;
use Usjnet\Sso\Support\HandlesSsoLogout;

class ValidateSsoToken
{
    use AuthenticatesSsoRequest;
    use HandlesSsoLogout;

    public function __construct(private readonly SsoAuthService $ssoAuthService)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $cookieName = (string) config('usjnet-sso.access_token_cookie', 'sso_access_token');
        $token = $request->bearerToken() ?? $request->cookie($cookieName);

        if (! $token) {
            return $this->clearAuthCookies(response()->json(['message' => 'Bearer token is required.'], 401));
        }

        try {
            $user = $this->ssoAuthService->validateAccessToken($token);
        } catch (Throwable) {
            $this->performSsoLogoutSafely($request, $this->ssoAuthService);
            $this->clearLocalSession($request);
            Auth::forgetGuards();

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
