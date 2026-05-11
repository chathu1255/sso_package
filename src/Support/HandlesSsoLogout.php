<?php

namespace Usjnet\Sso\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use Usjnet\Sso\SsoAuthService;

trait HandlesSsoLogout
{
    private function performSsoLogoutSafely(Request $request, SsoAuthService $ssoAuthService): void
    {
        $accessCookie = (string) config('usjnet-sso.access_token_cookie', 'sso_access_token');
        $accessToken = $request->bearerToken() ?? $request->cookie($accessCookie);

        if (is_string($accessToken) && trim($accessToken) !== '') {
            try {
                $ssoAuthService->logoutUser($accessToken);
            } catch (Throwable) {
                // Never block local cleanup if remote logout fails.
            }
        }
    }

    /**
     * Clear Laravel auth state (including Eloquent "system" users) and the session.
     */
    protected function purgeLocalAuthentication(Request $request): void
    {
        foreach (array_keys(config('auth.guards', [])) as $guardName) {
            if (! is_string($guardName) || $guardName === '') {
                continue;
            }
            try {
                $guard = Auth::guard($guardName);
                if (method_exists($guard, 'logout')) {
                    $guard->logout();
                }
            } catch (Throwable) {
                //
            }
        }

        Auth::forgetGuards();

        if ($request->hasSession()) {
            try {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            } catch (Throwable) {
                //
            }
        }
    }

    private function clearAuthCookies(Response $response): Response
    {
        $accessCookie = (string) config('usjnet-sso.access_token_cookie', 'sso_access_token');
        $refreshCookie = (string) config('usjnet-sso.refresh_token_cookie', 'sso_refresh_token');

        return $response
            ->withoutCookie($accessCookie)
            ->withoutCookie($refreshCookie)
            ->withoutCookie('accessToken')
            ->withoutCookie('privilages')
            ->withoutCookie('userState')
            ->withoutCookie('userStatus')
            ->withoutCookie('sjpEmail')
            ->withoutCookie('empNo');
    }
}

