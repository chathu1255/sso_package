<?php

namespace Usjnet\Sso\Http\Middleware;

use Closure;
use Illuminate\Auth\GenericUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;
use Usjnet\Sso\SsoAuthService;
use Usjnet\Sso\Support\HandlesSsoLogout;
use Throwable;

class ValidateSsoToken
{
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
            $authUser = new GenericUser($user);
            $request->attributes->set('sso_user', $user);
            $request->setUserResolver(static fn (): GenericUser => $authUser);
            Auth::setUser($authUser);
        } catch (Throwable) {
            $this->performSsoLogoutSafely($request, $this->ssoAuthService);
            $this->clearLocalSession($request);
            Auth::forgetGuards();
            return $this->clearAuthCookies(response()->json([
                'message' => 'Session expired or invalid. Please login again.',
            ], 401));
        }

        return $next($request);
    }
}
