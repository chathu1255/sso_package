<?php

/**
 * Environment variables (recommended prefix USJNET_SSO_*).
 * Legacy SSO_* keys are used as fallbacks where noted for existing deployments.
 */
return [

    'base_url' => env('USJNET_SSO_BASE_URL', env('SSO_BASE_URL')),

    'client_id' => env('USJNET_SSO_CLIENT_ID', env('SSO_CLIENT_ID')),

    'client_secret' => env('USJNET_SSO_CLIENT_SECRET', env('SSO_CLIENT_SECRET')),

    /** Must match the redirect_uri registered at your OAuth provider (backend-only callback). */
    'redirect_uri' => env('USJNET_SSO_REDIRECT_URI', env('SSO_REDIRECT_URI')),

    /** Legacy Blade SSO callback (optional). */
    'web_redirect_uri' => env('USJNET_SSO_WEB_REDIRECT_URI', env('SSO_WEB_REDIRECT_URI', env('SSO_REDIRECT_URI'))),

    /** SPA landing with code/bootstrap for legacy /api/auth/callback flows. */
    'frontend_callback_url' => env('USJNET_SSO_FRONTEND_CALLBACK_URL', env('SSO_FRONTEND_CALLBACK_URL', '')),

    /** Where the browser goes after successful /sso/spa/callback (no OAuth query). */
    'frontend_home_url' => env('USJNET_SSO_FRONTEND_HOME_URL', env('SSO_FRONTEND_HOME_URL', '')),

    'scope' => env('USJNET_SSO_SCOPE', env('SSO_SCOPE', 'view-user')),

    /** Optional OAuth authorize prompt (e.g. login). Empty = omit. */
    'authorize_prompt' => env('USJNET_SSO_AUTHORIZE_PROMPT', env('SSO_AUTHORIZE_PROMPT', '')),

    'timeout' => (int) env('USJNET_SSO_TIMEOUT', env('SSO_TIMEOUT', 15)),

    /**
     * SSO path for verifying the access token is still valid (GET, Bearer token, must return 2xx when OK).
     * Change if your IdP exposes a different introspection or profile URL.
     */
    'access_token_validation_path' => env('USJNET_SSO_TOKEN_VALIDATION_PATH', '/api/user'),

    /**
     * Remote logout on the SSO server (called from this app’s POST /api/auth/user_logout and on invalid-token cleanup).
     * POST must revoke the Bearer access token (or session) so GET access_token_validation_path returns non-2xx afterward.
     * Default matches USJNet SSO `/api/logout_passort_user`; set USJNET_SSO_LOGOUT_POST_PATH=/api/auth/logout for generic Passport.
     */
    'sso_logout_post_path' => env('USJNET_SSO_LOGOUT_POST_PATH', '/api/logout_passort_user'),

    /** Optional second logout call (GET). Empty string = skip. */
    'sso_logout_get_path' => trim((string) env('USJNET_SSO_LOGOUT_GET_PATH', '/api/user_logout')) ?: null,

    /**
     * Optional browser-facing SSO logout URL.
     * Use this when the SSO server must clear its own web session/cookies in the browser after API token revocation.
     * Example: http://10.32.192.185/logout?redirect={return_url}
     */
    'browser_logout_url' => trim((string) env('USJNET_SSO_BROWSER_LOGOUT_URL', '')) ?: null,

    /**
     * Optional redirect target used when building browser_logout_url.
     * If browser_logout_url contains {return_url}, it is replaced with the URL-encoded value of this setting.
     * Defaults to USJNET_SSO_FRONTEND_HOME_URL when omitted.
     */
    'browser_logout_redirect_url' => trim((string) env('USJNET_SSO_BROWSER_LOGOUT_REDIRECT_URL', env('USJNET_SSO_FRONTEND_HOME_URL', ''))) ?: null,

    /**
     * When the access token is dead on web routes: "oauth" = redirect to /sso/spa/redirect (default),
     * "frontend" = redirect to USJNET_SSO_FRONTEND_HOME_URL with ?login_error=session_expired (SPA login page).
     */
    'invalid_sso_web_session_redirect' => (($r = strtolower(trim((string) env('USJNET_SSO_INVALID_SESSION_REDIRECT', 'oauth')))) === 'frontend') ? 'frontend' : 'oauth',

    'access_token_cookie' => env('USJNET_SSO_ACCESS_TOKEN_COOKIE', env('SSO_ACCESS_TOKEN_COOKIE', 'sso_access_token')),

    'refresh_token_cookie' => env('USJNET_SSO_REFRESH_TOKEN_COOKIE', env('SSO_REFRESH_TOKEN_COOKIE', 'sso_refresh_token')),

    'access_token_cookie_minutes' => (int) env('USJNET_SSO_ACCESS_TOKEN_COOKIE_MINUTES', env('SSO_ACCESS_TOKEN_COOKIE_MINUTES', 60 * 12)),

    'refresh_token_cookie_minutes' => (int) env('USJNET_SSO_REFRESH_TOKEN_COOKIE_MINUTES', env('SSO_REFRESH_TOKEN_COOKIE_MINUTES', 60 * 24 * 14)),

    'cookie_secure' => env('USJNET_SSO_COOKIE_SECURE', env('SSO_COOKIE_SECURE', false)),

    'cookie_same_site' => env('USJNET_SSO_COOKIE_SAME_SITE', env('SSO_COOKIE_SAME_SITE', 'lax')),

    /** Path/domain for clearing SSO cookies (must match how cookies were set). Session domain if cookies were host-only null. */
    'cookie_path' => ($p = trim((string) env('USJNET_SSO_COOKIE_PATH', '/'))) !== '' ? $p : '/',

    'cookie_domain' => (($d = trim((string) env('USJNET_SSO_COOKIE_DOMAIN', ''))) !== '') ? $d : null,

    /**
     * Run StartSession on POST user_logout so Laravel session is invalidated (single-app Blade logout).
     * Disable if your stack cannot read the session cookie on api routes (e.g. encrypted session without matching middleware).
     */
    'api_user_logout_middleware' => filter_var(
        env('USJNET_SSO_USER_LOGOUT_USE_SESSION', true),
        FILTER_VALIDATE_BOOL,
        FILTER_NULL_ON_FAILURE
    ) ?? true
        ? [
            \Illuminate\Cookie\Middleware\EncryptCookies::class,
            \Illuminate\Session\Middleware\StartSession::class,
        ]
        : [],

    /** Prefix for bootstrap cache keys (legacy SPA callback). */
    'bootstrap_cache_prefix' => env('USJNET_SSO_BOOTSTRAP_CACHE_PREFIX', 'usjnet_sso_bootstrap:'),

    /** Routes registered under this URI prefix (default api → /api/auth/*). */
    'api_route_prefix' => env('USJNET_SSO_API_ROUTE_PREFIX', 'api'),

    /** Middleware for JSON auth routes (default Laravel api stack). */
    'api_route_middleware' => ['api'],

    /**
     * Optional extra route middleware alias for EnsureSsoWebAuthenticated (same as `sso.web`).
     * Example: set USJNET_SSO_WEB_MIDDLEWARE_ALIAS=auth to use Route::middleware('auth').
     * If your app already maps `auth` to Laravel's Authenticate middleware, remove that mapping or rely on
     * provider registration order — the last alias wins.
     */
    'web_middleware_alias' => trim((string) env('USJNET_SSO_WEB_MIDDLEWARE_ALIAS', '')) ?: null,

    /**
     * Which middleware the optional web alias points to:
     * - "sso"    => valid SSO cookie only (current/default behavior)
     * - "hybrid" => allow either a configured local-login guard session or valid SSO cookie
     */
    'web_middleware_mode' => (($mode = strtolower(trim((string) env('USJNET_SSO_WEB_MIDDLEWARE_MODE', 'sso')))) === 'hybrid')
        ? 'hybrid'
        : 'sso',

    /**
     * When true, {@see \Usjnet\Sso\Http\Middleware\VerifySsoAccessTokenLive} is appended to Laravel's `web` middleware group.
     * Any browser request that sends the SSO access cookie is re-checked with the IdP so logout/revocation elsewhere
     * is reflected on the next refresh or navigation (one round-trip per request; deduped with sso.web on same request).
     * Set USJNET_SSO_VERIFY_LIVE_ON_WEB_GROUP=false if you must avoid extra IdP calls on every page.
     */
    'verify_sso_access_token_on_web_middleware_group' => filter_var(
        env('USJNET_SSO_VERIFY_LIVE_ON_WEB_GROUP', true),
        FILTER_VALIDATE_BOOL,
        FILTER_NULL_ON_FAILURE
    ) ?? true,

    /**
     * Web paths that must not require an SSO access cookie when `sso.web` is on the same stack as `web`
     * (e.g. `sso.web` appended to the global `web` group). Prevents redirect loops on OAuth start/callback.
     * Paths are relative to the app root URL (no leading slash), e.g. "sso/spa/redirect".
     */
    'web_sso_public_paths' => [
        'sso/spa/redirect',
        'sso/spa/callback',
    ],

    /**
     * Optional guest-only paths/prefixes (no SSO redirect before login). Does NOT exempt the whole app after login.
     * After login, USJNET_SSO_LOCAL_LOGIN_GUARDS skips SSO on all routes. Example: admin/logout for guests only.
     */
    'web_sso_exempt_path_prefixes' => (($raw = env('USJNET_SSO_WEB_EXEMPT_PREFIXES', '')) !== '')
        ? array_values(array_filter(array_map(
            static fn (string $p): string => trim(str_replace('\\', '/', $p), '/'),
            explode(',', (string) $raw)
        )))
        : [],

    /**
     * Local login form URLs only (comma-separated, no leading slash). Default when env unset: admin/login.
     * Guests can open these without SSO redirect. After login, LOCAL_LOGIN_GUARDS skips SSO on every path.
     */
    'web_sso_local_login_paths' => (($raw = env('USJNET_SSO_WEB_LOCAL_LOGIN_PATHS', '__DEFAULT__')) === '__DEFAULT__')
        ? ['admin/login']
        : array_values(array_filter(array_map(
            static function (string $p): string {
                $p = str_replace('\\', '/', $p);

                return trim($p, '/');
            },
            explode(',', (string) $raw)
        ))),

    /**
     * When true (default), missing guards in USJNET_SSO_LOCAL_LOGIN_GUARDS are registered at boot
     * using the web/users provider (session driver). Set false to require manual config/auth.php entries.
     */
    'auto_register_local_login_guards' => filter_var(
        env('USJNET_SSO_AUTO_REGISTER_LOCAL_LOGIN_GUARDS', true),
        FILTER_VALIDATE_BOOLEAN
    ),

    /**
     * Guards that skip SSO on every route while authenticated (local login separate from SSO).
     * Comma-separated, e.g. USJNET_SSO_LOCAL_LOGIN_GUARDS=admin (same name as auth:admin).
     * When empty, guards are inferred from web_sso_local_login_paths parents (admin/login → admin) if that guard exists in auth.php.
     */
    'web_sso_local_login_guards' => (($raw = env('USJNET_SSO_LOCAL_LOGIN_GUARDS', '__DEFAULT__')) === '__DEFAULT__')
        ? ['admin']
        : array_values(array_filter(array_map(
            static fn (string $g): string => trim($g),
            explode(',', (string) $raw)
        ))),

    /**
     * Optional legacy single guard (same as listing one entry in USJNET_SSO_LOCAL_LOGIN_GUARDS).
     * Example: USJNET_SSO_SKIP_WEB_CHECKS_GUARD=admin
     */
    'skip_sso_web_checks_when_guard' => (($g = trim((string) env('USJNET_SSO_SKIP_WEB_CHECKS_GUARD', ''))) !== '') ? $g : null,

    /**
     * Optional: skip SSO web checks on ALL routes while session key is truthy. Set in your admin LoginController after login.
     * Example: USJNET_SSO_SKIP_WEB_CHECKS_SESSION_KEY=usjnet_local_admin then session(['usjnet_local_admin' => true]).
     * Clear the key (or session) on admin logout.
     */
    'skip_sso_web_checks_when_session_key' => (($k = trim((string) env('USJNET_SSO_SKIP_WEB_CHECKS_SESSION_KEY', ''))) !== '') ? $k : null,

    /**
     * Guards that receive the authenticated user after SSO validation (GenericUser or Eloquent User in system mode).
     * Comma-separated in env, e.g. "web,sanctum". Null = default guard from config/auth.php plus "web" and "api" if defined.
     */
    'auth_guards' => env('USJNET_SSO_AUTH_GUARDS')
        ? array_values(array_filter(array_map('trim', explode(',', (string) env('USJNET_SSO_AUTH_GUARDS')))))
        : null,

    /**
     * How Laravel auth is set after a valid SSO access token:
     * - "sso": Illuminate\Auth\GenericUser from SSO /api/user JSON (default).
     * - "system": Eloquent model from your users table matched by email (see system_user_* keys).
     */
    'auth_user_mode' => env('USJNET_SSO_AUTH_USER_MODE', 'sso'),

    /** Fully-qualified Eloquent model when auth_user_mode is "system". */
    'system_user_model' => env('USJNET_SSO_SYSTEM_USER_MODEL', 'App\\Models\\User'),

    /** Dot-path on SSO JSON for the email used to find the local user (e.g. "email"). */
    'system_user_email_attribute' => env('USJNET_SSO_SYSTEM_USER_EMAIL_ATTRIBUTE', 'email'),

    /** Database column on system_user_model used for lookup (usually "email"). */
    'system_user_email_column' => env('USJNET_SSO_SYSTEM_USER_EMAIL_COLUMN', 'email'),

    /** Match local user with case-insensitive email (recommended). */
    'system_user_match_case_insensitive' => filter_var(
        env('USJNET_SSO_SYSTEM_USER_EMAIL_CI', true),
        FILTER_VALIDATE_BOOL,
        FILTER_NULL_ON_FAILURE
    ) ?? true,

    /** When true, a minimal local row is created if no user matches (name + random password). */
    'create_system_user_if_missing' => filter_var(
        env('USJNET_SSO_CREATE_SYSTEM_USER_IF_MISSING', false),
        FILTER_VALIDATE_BOOL,
        FILTER_NULL_ON_FAILURE
    ) ?? false,

    /**
     * Comma-separated SSO JSON keys used for the local "name" field on auto-create (first non-empty wins).
     * Example: name,username
     */
    'system_user_name_attributes' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('USJNET_SSO_SYSTEM_USER_NAME_ATTRIBUTES', 'name,username'))
    ))),

    /** POST /api/auth/login — password grant to SSO. */
    'password_login_enabled' => env('USJNET_SSO_PASSWORD_LOGIN', true),

    /**
     * When true, POST login mirrors USJNet: user must exist in student_details (requires DB tables).
     * When false (default for generic installs), any successful SSO password login returns tokens.
     */
    'password_login_require_student_record' => env('USJNET_SSO_PASSWORD_LOGIN_REQUIRE_STUDENT', false),
];
