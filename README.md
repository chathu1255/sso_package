# usjnet/laravel-sso

Laravel package for SSO login with:

- `/sso/spa/redirect` and `/sso/spa/callback`
- `/api/auth/*`
- `sso.web` and `sso.token`
- `Auth::user()` support after middleware
- logout cleanup when SSO session is invalid

## 1. Requirements

- PHP **8.1+** (the package uses `readonly` properties; PHP 8.0 is not supported)
- Laravel **10.x, 11.x, or 12.x** (Composer resolves `illuminate/*` and Symfony to the versions your app uses)
- A running **OAuth2/OIDC-style SSO** (e.g. Laravel Passport) exposing:
  - `GET /oauth/authorize`
  - `POST /oauth/token`
  - `GET /api/user` (token introspection / profile)
  - Logout: `POST /api/auth/logout` and/or `GET /api/user_logout` (both are called for compatibility)

---

## 2. Install

### Path repository (local package)

If your Laravel app lives next to `packages/usjnet/laravel-sso` (for example you cloned **USJNet-V2.0**), add to your app’s **`composer.json`**:

```json
"repositories": [
    {
        "type": "path",
        "url": "../../packages/usjnet/laravel-sso"
    }
],
"require": {
    "usjnet/laravel-sso": "@dev"
}
```

Adjust `url` relative to your Laravel root (example: from `BackEnd/Backend` use `"../../packages/usjnet/laravel-sso"`).

Then:

```bash
composer update usjnet/laravel-sso
```

Windows absolute-path example you can copy:

```json
"repositories": [
    {
        "type": "path",
        "url": "d:/Git/USJNet-V2.0/packages/usjnet/laravel-sso"
    }
],
"require": {
    "usjnet/laravel-sso": "@dev"
}
```

Laravel **auto-discovers** `Usjnet\Sso\UsjnetSsoServiceProvider`.

### Packagist (future)

If the package is published as `usjnet/laravel-sso`, use:

```bash
composer require usjnet/laravel-sso
```

---

## 3. Configure

Add to **`.env`** (you can keep legacy `SSO_*` names; `USJNET_SSO_*` overrides them):

```env
APP_URL=http://127.0.0.1:8000

USJNET_SSO_BASE_URL=http://127.0.0.1:8001
USJNET_SSO_CLIENT_ID=your-client-id
USJNET_SSO_CLIENT_SECRET=your-client-secret

# Must match the redirect URL registered at the SSO app (backend only)
USJNET_SSO_REDIRECT_URI=http://127.0.0.1:8000/sso/spa/callback

# Where the SPA opens after login (no ?code= in URL)
USJNET_SSO_FRONTEND_HOME_URL=http://127.0.0.1:3000/home

# Optional: legacy callback+bootstrap landing (only if you use /api/auth/callback from IdP)
# USJNET_SSO_FRONTEND_CALLBACK_URL=http://127.0.0.1:3000/user_callback

USJNET_SSO_SCOPE=view-user
```

Register at the SSO server:

- **Redirect URI** = exact value of `USJNET_SSO_REDIRECT_URI` (same host as `APP_URL` + path `/sso/spa/callback`).

Use **one hostname style** everywhere (`127.0.0.1` **or** `localhost`, not mixed).

Default scope is **`view-user`** if you do not change it.

---

## 4. Run setup

```bash
php artisan vendor:publish --tag=usjnet-sso-config
php artisan usjnet-sso:install
php artisan usjnet-sso:doctor
```

`usjnet-sso:install` asks project style and writes recommended `.env` values:

- **`separate`**: frontend and backend are separate apps/domains (asks SPA CORS origins).
- **`single`**: frontend + backend in one Laravel app/domain (uses same-origin defaults).

You can re-run the installer anytime to update values.
If `config/cors.php` is missing, installer creates it with `supports_credentials=true` and env-driven `CORS_ALLOWED_ORIGINS`.

`usjnet-sso:doctor` prints **PASS/FAIL** for:

- required SSO env/config values
- key routes (`/sso/spa/*`, `/api/auth/*`)
- CORS credentials settings
- redirect URI host alignment with `APP_URL`

Use doctor after changing domains, env, CORS, or route prefixes.

## 5. Required manual changes

### Session

`/sso/spa/redirect` stores OAuth `state` in session. Use **`SESSION_DRIVER`** appropriate for your environment (e.g. `file` or `database` in production).

### Cookie encryption exceptions (`bootstrap/app.php`)

Exclude SSO cookie names from Laravel’s cookie encryption (same names as in config, default `sso_access_token`, `sso_refresh_token`).

**Laravel 11+** (`bootstrap/app.php`):

```php
->withMiddleware(function (Middleware $middleware): void {
    $middleware->encryptCookies(except: [
        'sso_access_token',
        'sso_refresh_token',
    ]);
})
```

**Laravel 10** (`app/Http/Middleware/EncryptCookies.php`): add the same names to the `$except` array property on `EncryptCookies`.

The package registers `sso.token` and `sso.web` automatically.

## 6. Use in your app

### Web routes

If your new project has backend-rendered pages (Blade/Inertia/etc.), protect those routes with:

```php
Route::middleware('sso.web')->group(function (): void {
    Route::view('/home', 'home');
});
```

Behavior:

- if SSO access cookie is missing: redirect to `/sso/spa/redirect?state=...`
- if cookie exists but token is invalid: package performs SSO logout, clears local session/cookies, then redirects with `prompt_login=1`
- if token is valid: request user is available as `sso_user`, `$request->user()`, and `Auth::user()`

### Invalid-session cleanup

Both middleware paths now enforce hard cleanup on invalid/expired sessions:

- **`sso.web`**: remote SSO logout (best effort), local session invalidation, cookie cleanup, redirect to login
- **`sso.token`**: same cleanup + JSON `401` (`Session expired or invalid. Please login again.`)

This helps enforce global logout behavior across connected apps when SSO token is no longer valid.

### Access user in controllers

After `sso.web` or `sso.token` middleware runs, all of these work:

```php
$request->user();
$request->attributes->get('sso_user');
Auth::user();
```

Quick built-in debug endpoint:

```text
GET /api/auth/whoami
```

It returns:

- `auth_user`
- `request_user`
- `sso_user`

### CORS (`config/cors.php`)

Your SPA must call the API with **credentials**:

- `'supports_credentials' => true`
- `'allowed_origins'` must list your SPA origin(s), e.g. `http://127.0.0.1:3000`

Example env:

```env
CORS_ALLOWED_ORIGINS=http://127.0.0.1:3000,http://localhost:3000
```

---

## 7. Routes

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/sso/spa/redirect` | Start OAuth (session stores `state`; optional `prompt_login=1`) |
| GET | `/sso/spa/callback` | Exchange code, set cookies, redirect to `USJNET_SSO_FRONTEND_HOME_URL` |
| GET | `/api/auth/redirect` | JSON or redirect helper |
| GET | `/api/auth/callback` | Legacy browser callback → bootstrap redirect |
| POST | `/api/auth/bootstrap` | Redeem bootstrap id |
| POST | `/api/auth/login` | Password grant (optional; see below) |
| POST | `/api/auth/exchange-code` | JSON code exchange |
| POST | `/api/auth/refresh` | Refresh tokens + optional cookie refresh |
| POST | `/api/auth/user_logout` | Logout SSO + clear cookies |
| GET | `/api/auth/me` | Current user (`sso.token`) |
| GET | `/api/auth/whoami` | Debug endpoint for `Auth::user()`, `$request->user()`, and raw `sso_user` |

Prefix **`api`** is configurable (`USJNET_SSO_API_ROUTE_PREFIX`).

---

## 8. Frontend

Point your login page to:

```text
{REACT_APP_API_BASE_URL}/sso/spa/redirect?state=<uuid>
```

Use **`axios`** with **`withCredentials: true`** and base URL `{API}/api`. After login, call protected endpoints; the browser sends HttpOnly cookies to the API origin.

Reference implementation files live in **USJNet** under `FrontEnd/usjnet/src/` (`config.js`, `api/axios.js`, `components/auth/user-login.jsx`, `utils/logout.js`).

---

## 9. Optional USJNet-style database checks

By default:

- `USJNET_SSO_PASSWORD_LOGIN_REQUIRE_STUDENT=false` — password login returns tokens for any SSO-valid user.
- `GET /api/auth/me` returns SSO profile plus `role_id: null` and `permission_codes: []`.

If you set **`USJNET_SSO_PASSWORD_LOGIN_REQUIRE_STUDENT=true`** and maintain **`student_details`** + **`role_permissions`** like USJNet, the package enables the same **login gate** and **role/permission** enrichment as the full application.

---

## 10. Customize after authentication

Use app middleware/services for:

- role-based access
- local user sync
- deny suspended users
- email-domain rules
- mapping SSO fields to local roles

Recommended pattern:

```php
Route::middleware(['sso.web', 'check.role'])->group(function (): void {
    Route::view('/admin', 'admin.dashboard');
});
```

Rule:

- package = authentication
- app = authorization / business rules

## 11. Avoid duplicate routes

If you already defined `/sso/spa/*` or `/api/auth/*` in your app, remove the duplicates or uninstall in-house copies before installing this package.

---

## 12. Troubleshooting

| Issue | Check |
|-------|--------|
| `invalid_state` | Same tab session; one redirect from SPA; `APP_URL` matches cookie domain |
| `invalid_grant` | `USJNET_SSO_REDIRECT_URI` matches SSO registration and token exchange |
| 401 on `/api/auth/me` | `Authorization: Bearer` or HttpOnly cookie; `withCredentials` on SPA |
| Package not loading | `composer dump-autoload`; clear config cache |

---

## 13. License

MIT (align with your organization’s policy before publishing).
