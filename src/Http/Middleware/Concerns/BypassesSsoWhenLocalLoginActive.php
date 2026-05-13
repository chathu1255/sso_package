<?php

namespace Usjnet\Sso\Http\Middleware\Concerns;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Throwable;

trait BypassesSsoWhenLocalLoginActive
{
    /**
     * When true for this request, skip SSO cookie/token validation on every path (not only /admin/*).
     * Use after successful login via e.g. /admin/login: either authenticate a dedicated guard or set a session flag.
     */
    protected function shouldBypassAllSsoChecks(Request $request): bool
    {
        $guard = config('usjnet-sso.skip_sso_web_checks_when_guard');
        if (is_string($guard) && trim($guard) !== '') {
            try {
                if (Auth::guard(trim($guard))->check()) {
                    return true;
                }
            } catch (Throwable) {
                //
            }
        }

        $sessionKey = config('usjnet-sso.skip_sso_web_checks_when_session_key');
        if (! is_string($sessionKey) || trim($sessionKey) === '') {
            return false;
        }

        $sessionKey = trim($sessionKey);
        if (! $request->hasSession()) {
            return false;
        }

        return (bool) $request->session()->get($sessionKey);
    }
}
