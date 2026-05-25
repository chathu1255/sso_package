<?php

namespace Usjnet\Sso\Http\Middleware\Concerns;

use Illuminate\Http\Request;
use Usjnet\Sso\Support\LocalLoginZone;

trait SkipsSsoWebCookieGate
{
    /**
     * OAuth handshake, local login entry URLs, or an authenticated local guard (e.g. admin) → skip SSO middleware.
     */
    protected function shouldSkipSsoWebMiddleware(Request $request): bool
    {
        return $this->allowsWithoutAccessCookie($request)
            || LocalLoginZone::shouldSkipSsoWebMiddleware($request);
    }

    /**
     * OAuth start/callback must never be gated by the access-token cookie.
     */
    protected function allowsWithoutAccessCookie(Request $request): bool
    {
        if ($request->routeIs('usjnet.sso.spa.redirect', 'usjnet.sso.spa.callback')) {
            return true;
        }

        $path = LocalLoginZone::requestPath($request);
        $paths = config('usjnet-sso.web_sso_public_paths', []);
        if (! is_array($paths)) {
            return false;
        }
        foreach ($paths as $allowed) {
            $allowed = mb_strtolower(trim((string) $allowed, '/'));
            if ($allowed !== '' && $path === $allowed) {
                return true;
            }
        }

        return false;
    }
}
