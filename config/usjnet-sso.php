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

    'access_token_cookie' => env('USJNET_SSO_ACCESS_TOKEN_COOKIE', env('SSO_ACCESS_TOKEN_COOKIE', 'sso_access_token')),

    'refresh_token_cookie' => env('USJNET_SSO_REFRESH_TOKEN_COOKIE', env('SSO_REFRESH_TOKEN_COOKIE', 'sso_refresh_token')),

    'access_token_cookie_minutes' => (int) env('USJNET_SSO_ACCESS_TOKEN_COOKIE_MINUTES', env('SSO_ACCESS_TOKEN_COOKIE_MINUTES', 60 * 12)),

    'refresh_token_cookie_minutes' => (int) env('USJNET_SSO_REFRESH_TOKEN_COOKIE_MINUTES', env('SSO_REFRESH_TOKEN_COOKIE_MINUTES', 60 * 24 * 14)),

    'cookie_secure' => env('USJNET_SSO_COOKIE_SECURE', env('SSO_COOKIE_SECURE', false)),

    'cookie_same_site' => env('USJNET_SSO_COOKIE_SAME_SITE', env('SSO_COOKIE_SAME_SITE', 'lax')),

    /** Prefix for bootstrap cache keys (legacy SPA callback). */
    'bootstrap_cache_prefix' => env('USJNET_SSO_BOOTSTRAP_CACHE_PREFIX', 'usjnet_sso_bootstrap:'),

    /** Routes registered under this URI prefix (default api → /api/auth/*). */
    'api_route_prefix' => env('USJNET_SSO_API_ROUTE_PREFIX', 'api'),

    /** Middleware for JSON auth routes (default Laravel api stack). */
    'api_route_middleware' => ['api'],

    /**
     * Guards that receive the SSO GenericUser (so Auth::guard('api')->user() works, not only the default guard).
     * Comma-separated in env, e.g. "web,sanctum". Null = default guard from config/auth.php plus "web" and "api" if defined.
     */
    'auth_guards' => env('USJNET_SSO_AUTH_GUARDS')
        ? array_values(array_filter(array_map('trim', explode(',', (string) env('USJNET_SSO_AUTH_GUARDS')))))
        : null,

    /** POST /api/auth/login — password grant to SSO. */
    'password_login_enabled' => env('USJNET_SSO_PASSWORD_LOGIN', true),

    /**
     * When true, POST login mirrors USJNet: user must exist in student_details (requires DB tables).
     * When false (default for generic installs), any successful SSO password login returns tokens.
     */
    'password_login_require_student_record' => env('USJNET_SSO_PASSWORD_LOGIN_REQUIRE_STUDENT', false),
];
