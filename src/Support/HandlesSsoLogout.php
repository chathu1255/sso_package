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
        $accessToken = $this->resolveLogoutAccessToken($request);

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
        $path = (string) config('usjnet-sso.cookie_path', '/');
        $domain = config('usjnet-sso.cookie_domain');

        return $response
            ->withoutCookie($accessCookie, $path, $domain)
            ->withoutCookie($refreshCookie, $path, $domain)
            ->withoutCookie('accessToken', $path, $domain)
            ->withoutCookie('privilages', $path, $domain)
            ->withoutCookie('userState', $path, $domain)
            ->withoutCookie('userStatus', $path, $domain)
            ->withoutCookie('sjpEmail', $path, $domain)
            ->withoutCookie('empNo', $path, $domain);
    }

    private function resolveLogoutAccessToken(Request $request): ?string
    {
        foreach ([
            $request->bearerToken(),
            $this->decodeTokenCandidate($request->cookie((string) config('usjnet-sso.access_token_cookie', 'sso_access_token'))),
            $this->decodeTokenCandidate($request->cookie('accessToken')),
            $request->input('access_token'),
            $request->hasSession() ? $request->session()->get('usjnet_sso.access_token') : null,
        ] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return null;
    }

    private function decodeTokenCandidate(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        try {
            $decrypted = app('encrypter')->decrypt($trimmed, false);
            if (is_string($decrypted) && trim($decrypted) !== '') {
                return trim($decrypted);
            }
        } catch (Throwable) {
            //
        }

        return $trimmed;
    }
}

