<?php

namespace Usjnet\Sso\Support;

use Illuminate\Http\Request;
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

    private function clearLocalSession(Request $request): void
    {
        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
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

