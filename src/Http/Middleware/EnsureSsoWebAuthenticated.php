<?php

namespace Usjnet\Sso\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use Usjnet\Sso\SsoAuthService;
use Usjnet\Sso\Support\AuthenticatesSsoRequest;
use Usjnet\Sso\Support\HandlesSsoLogout;

class EnsureSsoWebAuthenticated
{
    use AuthenticatesSsoRequest;
    use HandlesSsoLogout;

    public function __construct(private readonly SsoAuthService $ssoAuthService)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $cookieName = (string) config('usjnet-sso.access_token_cookie', 'sso_access_token');
        $token = $request->cookie($cookieName);

        if (! is_string($token) || trim($token) === '') {
            return $this->redirectToSso($request);
        }

        try {
            $user = $this->ssoAuthService->validateAccessToken($token);
            $this->authenticateSsoRequest($request, $user);
        } catch (Throwable) {
            $this->performSsoLogoutSafely($request, $this->ssoAuthService);
            $this->clearLocalSession($request);
            Auth::forgetGuards();
            return $this->clearAuthCookies($this->redirectToSso($request, true));
        }

        return $next($request);
    }

    private function redirectToSso(Request $request, bool $forceLogin = false): Response
    {
        $request->session()->put('url.intended', $request->fullUrl());

        $state = (string) Str::uuid();
        $query = ['state' => $state];
        if ($forceLogin) {
            $query['prompt_login'] = 1;
        }

        return redirect('/sso/spa/redirect?'.http_build_query($query));
    }
}

