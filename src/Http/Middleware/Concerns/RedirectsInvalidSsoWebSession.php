<?php

namespace Usjnet\Sso\Http\Middleware\Concerns;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

trait RedirectsInvalidSsoWebSession
{
    protected function redirectToSso(Request $request, bool $forceLogin = false): Response
    {
        $request->session()->put('url.intended', $request->fullUrl());

        $state = (string) Str::uuid();
        $query = ['state' => $state];
        if ($forceLogin) {
            $query['prompt_login'] = 1;
        }

        return redirect('/sso/spa/redirect?'.http_build_query($query));
    }

    /**
     * When the SSO access token is no longer valid: OAuth re-login, or SPA login URL (see USJNET_SSO_INVALID_SESSION_REDIRECT).
     */
    protected function redirectAfterInvalidSsoWebToken(Request $request, bool $forceLogin = false): Response
    {
        if (config('usjnet-sso.invalid_sso_web_session_redirect') === 'frontend') {
            $home = rtrim((string) config('usjnet-sso.frontend_home_url', ''), '/');
            if ($home !== '') {
                return redirect()->away($home.'?'.http_build_query([
                    'login_error' => 'session_expired',
                    'login_error_description' => 'Your SSO session is no longer valid. Please sign in again.',
                ]));
            }
        }

        return $this->redirectToSso($request, $forceLogin);
    }

    /**
     * Invalid token on web: JSON for XHR/SPA, otherwise redirect to re-login.
     */
    protected function respondInvalidSsoWebToken(Request $request, bool $forceLogin = true): Response
    {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Session expired or invalid. Please login again.',
            ], 401);
        }

        return $this->redirectAfterInvalidSsoWebToken($request, $forceLogin);
    }
}
