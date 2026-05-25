<?php

namespace Usjnet\Sso\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use Usjnet\Sso\SsoAuthService;
use Usjnet\Sso\Support\LocalLoginZone;

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
     * Invalid/expired SSO token: keep local admin (or other bypass guard) session; only clear SSO cookies and SSO guards.
     */
    protected function handleInvalidSsoTokenForWeb(Request $request, SsoAuthService $ssoAuthService, Response $invalidResponse, ?callable $continue = null): Response
    {
        $this->performSsoLogoutSafely($request, $ssoAuthService);

        if (LocalLoginZone::hasActiveLocalGuardSession($request)) {
            $response = $continue !== null ? $continue() : $invalidResponse;

            return $this->clearAuthCookies($response);
        }

        $this->purgeLocalAuthentication($request);

        return $this->clearAuthCookies($invalidResponse);
    }

    /**
     * Clear Laravel auth state (including Eloquent "system" users) and the session.
     */
    protected function purgeLocalAuthentication(Request $request): void
    {
        $preserveGuards = array_flip(LocalLoginZone::bypassGuards());

        foreach (array_keys(config('auth.guards', [])) as $guardName) {
            if (! is_string($guardName) || $guardName === '') {
                continue;
            }
            if (isset($preserveGuards[$guardName])) {
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

