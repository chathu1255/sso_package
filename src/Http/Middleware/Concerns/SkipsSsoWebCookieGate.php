<?php

namespace Usjnet\Sso\Http\Middleware\Concerns;

use Illuminate\Http\Request;

trait SkipsSsoWebCookieGate
{
    /**
     * OAuth start/callback must never be gated by the access-token cookie, or we redirect to ourselves forever
     * when `sso.web` is part of the global `web` middleware group.
     */
    protected function allowsWithoutAccessCookie(Request $request): bool
    {
        if ($request->routeIs('usjnet.sso.spa.redirect', 'usjnet.sso.spa.callback')) {
            return true;
        }

        $path = trim((string) $request->path(), '/');
        $paths = config('usjnet-sso.web_sso_public_paths', []);
        if (! is_array($paths)) {
            return false;
        }
        foreach ($paths as $allowed) {
            $allowed = trim((string) $allowed, '/');
            if ($allowed !== '' && $path === $allowed) {
                return true;
            }
        }

        return false;
    }

    /**
     * Local session areas: explicit prefixes and/or paths derived from configured local login URLs (see web_sso_local_login_paths).
     */
    protected function isWebSsoExemptByConfiguredPaths(Request $request): bool
    {
        $path = trim((string) $request->path(), '/');

        $loginPaths = config('usjnet-sso.web_sso_local_login_paths', []);
        if (is_array($loginPaths)) {
            foreach ($loginPaths as $loginPath) {
                $loginPath = trim(str_replace('\\', '/', (string) $loginPath), '/');
                if ($loginPath === '') {
                    continue;
                }
                if ($path === $loginPath) {
                    return true;
                }
                $parent = dirname($loginPath);
                if ($parent !== '.' && $parent !== '/' && $parent !== '') {
                    $parent = trim(str_replace('\\', '/', $parent), '/');
                    if ($parent !== '' && ($path === $parent || str_starts_with($path, $parent.'/'))) {
                        return true;
                    }
                }
            }
        }

        $prefixes = config('usjnet-sso.web_sso_exempt_path_prefixes', []);
        if (! is_array($prefixes)) {
            return false;
        }
        foreach ($prefixes as $prefix) {
            $prefix = trim((string) $prefix, '/');
            if ($prefix === '') {
                continue;
            }
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                return true;
            }
        }

        return false;
    }
}
