<?php

namespace Usjnet\Sso\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;
use Usjnet\Sso\SsoAuthService;

/**
 * SPA login: OAuth stays on the backend. SSO redirects only to this app.
 */
class SpaSsoController extends Controller
{
    public function __construct(private readonly SsoAuthService $authService)
    {
    }

    public function redirect(Request $request): RedirectResponse
    {
        $state = (string) $request->query('state', Str::uuid()->toString());
        $request->session()->put('sso_oauth_state', $state);
        Cache::put('usjnet_sso_state:'.$state, true, now()->addMinutes(10));

        $redirectUri = (string) config('usjnet-sso.redirect_uri', '');
        if ($redirectUri === '') {
            throw new HttpException(500, 'USJNET_SSO_REDIRECT_URI is not configured. Example: '.rtrim((string) config('app.url'), '/').'/sso/spa/callback');
        }

        $forceLogin = $request->boolean('prompt_login');
        $secure = (bool) config('usjnet-sso.cookie_secure', false);

        return redirect()
            ->away($this->authService->authorizeUrl($state, $redirectUri, $forceLogin))
            ->cookie('sso_oauth_state', $state, 10, '/', null, $secure, true, false, 'lax');
    }

    public function callback(Request $request): RedirectResponse
    {
        $home = rtrim((string) config('usjnet-sso.frontend_home_url', ''), '/');
        if ($home === '') {
            throw new HttpException(500, 'USJNET_SSO_FRONTEND_HOME_URL is not configured.');
        }

        if ($request->filled('error')) {
            return redirect()->away($home.'?'.http_build_query(array_filter([
                'login_error' => $request->query('error'),
                'login_error_description' => $request->query('error_description'),
            ], static fn ($v) => filled($v))));
        }

        $sessionState = $request->session()->pull('sso_oauth_state');
        $cookieState = (string) $request->cookie('sso_oauth_state', '');
        $storedState = $sessionState ?: $cookieState;
        $queryState = (string) $request->query('state', '');
        $matchesStoredState = $storedState !== '' && $queryState !== '' && hash_equals($storedState, $queryState);
        $matchesCachedState = $queryState !== '' && (bool) Cache::pull('usjnet_sso_state:'.$queryState, false);

        if (! $matchesStoredState && ! $matchesCachedState) {
            return redirect()->away($home.'?login_error=invalid_state');
        }

        $code = (string) $request->query('code', '');
        if ($code === '') {
            return redirect()->away($home.'?login_error=missing_code');
        }

        try {
            $token = $this->authService->exchangeAuthorizationCode($code);
            $this->authService->validateAccessToken($token->accessToken);
        } catch (Throwable $e) {
            return redirect()->away($home.'?'.http_build_query([
                'login_error' => 'token_exchange_failed',
                'login_error_description' => $e->getMessage() ?: 'SSO token exchange failed.',
            ]));
        }

        if ($request->hasSession()) {
            $request->session()->put('usjnet_sso.access_token', $token->accessToken);
            $request->session()->put('usjnet_sso.refresh_token', $token->refreshToken);
        }

        $accessName = (string) config('usjnet-sso.access_token_cookie', 'sso_access_token');
        $refreshName = (string) config('usjnet-sso.refresh_token_cookie', 'sso_refresh_token');
        $minutes = (int) config('usjnet-sso.access_token_cookie_minutes', 60 * 12);
        $refreshMinutes = (int) config('usjnet-sso.refresh_token_cookie_minutes', 60 * 24 * 14);
        $secure = (bool) config('usjnet-sso.cookie_secure', false);
        $sameSite = (string) config('usjnet-sso.cookie_same_site', 'lax');

        $response = redirect()->away($home);
        $response->cookie($accessName, $token->accessToken, $minutes, '/', null, $secure, true, false, $sameSite);

        if ($token->refreshToken !== null && $token->refreshToken !== '') {
            $response->cookie($refreshName, (string) $token->refreshToken, $refreshMinutes, '/', null, $secure, true, false, $sameSite);
        }

        $response->cookie('userState', 'logged', $minutes, '/', null, $secure, false, false, $sameSite);
        $response->cookie('privilages', json_encode(['logged'], JSON_UNESCAPED_SLASHES), $minutes, '/', null, $secure, false, false, $sameSite);

        return $response->withoutCookie('sso_oauth_state');
    }
}
