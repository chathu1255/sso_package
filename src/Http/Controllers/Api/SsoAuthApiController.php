<?php

namespace Usjnet\Sso\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use Usjnet\Sso\Http\Requests\BootstrapRequest;
use Usjnet\Sso\Http\Requests\LoginRequest;
use Usjnet\Sso\Http\Requests\RefreshTokenRequest;
use Usjnet\Sso\Http\Requests\TokenExchangeRequest;
use Usjnet\Sso\Models\SsoToken;
use Usjnet\Sso\SsoAuthService;

class SsoAuthApiController extends Controller
{
    public function __construct(private readonly SsoAuthService $authService)
    {
    }

    public function redirectToSso(Request $request): JsonResponse|RedirectResponse
    {
        $state = (string) $request->query('state', Str::uuid()->toString());
        $authorizeUrl = $this->authService->authorizeUrl($state);

        if ($request->boolean('raw') || $request->expectsJson()) {
            return response()->json([
                'state' => $state,
                'authorize_url' => $authorizeUrl,
            ]);
        }

        return redirect()->away($authorizeUrl);
    }

    public function handleSsoCallback(Request $request): JsonResponse|RedirectResponse
    {
        $payload = array_filter([
            'code' => $request->query('code'),
            'state' => $request->query('state'),
            'error' => $request->query('error'),
            'error_description' => $request->query('error_description'),
        ], static fn (mixed $value): bool => filled($value));

        if ($request->boolean('raw') || $request->expectsJson()) {
            return response()->json($payload);
        }

        $frontendCallback = rtrim((string) config('usjnet-sso.frontend_callback_url'), '/');

        if ($frontendCallback === '') {
            abort(500, 'USJNET_SSO_FRONTEND_CALLBACK_URL is not configured.');
        }

        if ($request->filled('error')) {
            $query = http_build_query(array_filter([
                'error' => $request->query('error'),
                'error_description' => $request->query('error_description'),
                'state' => $request->query('state'),
            ], static fn (mixed $v): bool => filled($v)));

            return redirect()->away($frontendCallback.($query !== '' ? '?'.$query : ''));
        }

        $code = (string) $request->query('code', '');
        if ($code === '') {
            $query = http_build_query([
                'error' => 'missing_code',
                'error_description' => 'No authorization code was returned.',
            ]);

            return redirect()->away($frontendCallback.'?'.$query);
        }

        try {
            $token = $this->authService->exchangeAuthorizationCode($code);
            $this->authService->validateAccessToken($token->accessToken);
        } catch (Throwable $e) {
            $query = http_build_query([
                'error' => 'token_exchange_failed',
                'error_description' => $e->getMessage() ?: 'SSO token exchange failed.',
            ]);

            return redirect()->away($frontendCallback.'?'.$query);
        }

        $bootstrapId = (string) Str::uuid();
        $prefix = (string) config('usjnet-sso.bootstrap_cache_prefix', 'usjnet_sso_bootstrap:');
        Cache::put(
            $prefix.$bootstrapId,
            $token->toFrontendPayload(),
            now()->addMinutes(2)
        );

        $redirectQuery = array_filter([
            'bootstrap' => $bootstrapId,
            'state' => $request->query('state'),
        ], static fn (mixed $v): bool => filled($v));

        return redirect()->away($frontendCallback.'?'.http_build_query($redirectQuery));
    }

    public function bootstrap(BootstrapRequest $request): JsonResponse
    {
        $prefix = (string) config('usjnet-sso.bootstrap_cache_prefix', 'usjnet_sso_bootstrap:');
        $id = (string) $request->input('bootstrap', '');
        $data = Cache::pull($prefix.$id);

        if (! is_array($data) || empty($data['access_token'])) {
            return response()->json([
                'message' => 'Invalid or expired login session. Please sign in again.',
            ], 410);
        }

        return response()->json($data);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        if (! config('usjnet-sso.password_login_enabled', true)) {
            return response()->json(['message' => 'Password login is disabled.'], 503);
        }

        $token = $this->authService->requestPasswordToken(
            (string) $request->input('username', ''),
            (string) $request->input('password', ''),
            (string) $request->input('scope', ''),
        );

        if (config('usjnet-sso.password_login_require_student_record', false)) {
            if (! Schema::hasTable('student_details')) {
                return response()->json([
                    'message' => 'password_login_require_student_record is true but table student_details does not exist.',
                ], 500);
            }

            $ssoUser = $this->authService->validateAccessToken($token->accessToken);
            $loginId = strtolower(trim((string) $request->input('username', '')));
            $email = strtolower(trim((string) data_get($ssoUser, 'email', '')));

            $studentExists = DB::table('student_details')
                ->when(
                    $email !== '',
                    static fn ($query) => $query->whereRaw('LOWER(sjp_mail) = ?', [$email]),
                    static fn ($query) => $query->whereRaw('LOWER(reg_no) = ?', [$loginId]),
                )
                ->exists();

            if (! $studentExists) {
                try {
                    $this->authService->logoutUser($token->accessToken);
                } catch (Throwable) {
                }

                return response()->json([
                    'message' => 'User is not registered in student_details.',
                ], 403);
            }
        }

        return response()->json($token->toFrontendPayload());
    }

    public function exchangeCode(TokenExchangeRequest $request): JsonResponse
    {
        $token = $this->authService->exchangeAuthorizationCode((string) $request->input('code', ''));

        return response()->json($token->toFrontendPayload());
    }

    public function refresh(RefreshTokenRequest $request): JsonResponse
    {
        $token = $this->authService->refreshToken(
            (string) $request->input('refresh_token', ''),
            (string) $request->input('scope', ''),
        );

        $response = response()->json($token->toFrontendPayload());

        return $this->withSsoHttpOnlyCookies($response, $token);
    }

    public function me(Request $request): JsonResponse
    {
        $ssoUser = $request->attributes->get('sso_user', []);

        $payload = array_merge((array) $ssoUser, [
            'role_id' => null,
            'permission_codes' => [],
        ]);

        if (config('usjnet-sso.password_login_require_student_record', false) && Schema::hasTable('student_details') && Schema::hasTable('role_permissions')) {
            $roleId = $this->resolveRoleIdUsjnet($ssoUser);
            $permissionCodes = [];
            if ($roleId !== null) {
                $permissionCodes = DB::table('role_permissions')
                    ->where('role', $roleId)
                    ->pluck('permission')
                    ->map(fn ($code) => trim((string) $code))
                    ->filter()
                    ->values()
                    ->all();
            }
            $payload = array_merge((array) $ssoUser, [
                'role_id' => $roleId,
                'permission_codes' => $permissionCodes,
            ]);
        }

        return response()->json($payload);
    }

    private function resolveRoleIdUsjnet(array $ssoUser): ?int
    {
        $email = strtolower(trim((string) data_get($ssoUser, 'email', '')));
        $employeeNumber = trim((string) data_get($ssoUser, 'employeeNumber', ''));
        $accountCode = trim((string) data_get($ssoUser, 'accountCode', ''));

        $query = DB::table('student_details')->select(['role']);

        if ($email !== '') {
            $user = (clone $query)->whereRaw('LOWER(sjp_mail) = ?', [$email])->first();
            if ($user && isset($user->role)) {
                return (int) $user->role;
            }
        }

        $candidateRegNos = collect([$employeeNumber, $accountCode])
            ->map(fn ($value) => preg_replace('/[^0-9A-Za-z]/', '', (string) $value))
            ->filter()
            ->unique()
            ->values();

        foreach ($candidateRegNos as $regNo) {
            $user = (clone $query)->where('reg_no', $regNo)->first();
            if ($user && isset($user->role)) {
                return (int) $user->role;
            }
        }

        return null;
    }

    public function userLogout(Request $request): JsonResponse
    {
        $accessCookie = (string) config('usjnet-sso.access_token_cookie', 'sso_access_token');
        $accessToken = $request->bearerToken() ?? $request->cookie($accessCookie);
        $localStatus = [
            'session_invalidated' => false,
            'token_revoked' => false,
        ];

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            $localStatus['session_invalidated'] = true;
        }

        $user = Auth::guard('api')->user();
        if ($user && method_exists($user, 'token') && $user->token()) {
            $user->token()->revoke();
            $localStatus['token_revoked'] = true;
        }

        try {
            $logoutResult = $this->authService->logoutUser($accessToken);

            return $this->withoutSsoCookies(response()->json([
                'message' => 'User logged out successfully.',
                'backend_status' => $localStatus,
                'sso_status' => $logoutResult['status'],
                'sso_response' => $logoutResult['body'],
            ], 200))
                ->withoutCookie('accessToken')
                ->withoutCookie('privilages')
                ->withoutCookie('userState')
                ->withoutCookie('userStatus')
                ->withoutCookie('sjpEmail')
                ->withoutCookie('empNo');
        } catch (Throwable $exception) {
            return $this->withoutSsoCookies(response()->json([
                'message' => 'Unable to logout from SSO.',
                'backend_status' => $localStatus,
                'error' => $exception->getMessage(),
            ], 500))
                ->withoutCookie('accessToken')
                ->withoutCookie('privilages')
                ->withoutCookie('userState')
                ->withoutCookie('userStatus')
                ->withoutCookie('sjpEmail')
                ->withoutCookie('empNo');
        }
    }

    private function withSsoHttpOnlyCookies(JsonResponse $response, SsoToken $token): JsonResponse
    {
        $accessName = (string) config('usjnet-sso.access_token_cookie', 'sso_access_token');
        $refreshName = (string) config('usjnet-sso.refresh_token_cookie', 'sso_refresh_token');
        $minutes = (int) config('usjnet-sso.access_token_cookie_minutes', 60 * 12);
        $refreshMinutes = (int) config('usjnet-sso.refresh_token_cookie_minutes', 60 * 24 * 14);
        $secure = (bool) config('usjnet-sso.cookie_secure', false);
        $sameSite = (string) config('usjnet-sso.cookie_same_site', 'lax');

        $response->cookie($accessName, $token->accessToken, $minutes, '/', null, $secure, true, false, $sameSite);

        if ($token->refreshToken !== null && $token->refreshToken !== '') {
            $response->cookie($refreshName, (string) $token->refreshToken, $refreshMinutes, '/', null, $secure, true, false, $sameSite);
        }

        $response->cookie('userState', 'logged', $minutes, '/', null, $secure, false, false, $sameSite);
        $response->cookie('privilages', json_encode(['logged'], JSON_UNESCAPED_SLASHES), $minutes, '/', null, $secure, false, false, $sameSite);

        return $response;
    }

    private function withoutSsoCookies(Response $response): Response
    {
        $accessName = (string) config('usjnet-sso.access_token_cookie', 'sso_access_token');
        $refreshName = (string) config('usjnet-sso.refresh_token_cookie', 'sso_refresh_token');

        return $response
            ->withoutCookie($accessName)
            ->withoutCookie($refreshName);
    }
}
