# usjnet/laravel-sso

Laravel package for SSO login with:

- `/sso/spa/redirect` and `/sso/spa/callback`
- `/api/auth/*`
- `sso.web` (and optional alias, e.g. `auth` via `USJNET_SSO_WEB_MIDDLEWARE_ALIAS`) and `sso.token`
- **`sso.web.live`** — optional global re-check of the SSO cookie on every `web` request (see below); enabled by default via config
- `Auth::user()` support after middleware
- logout cleanup when SSO session is invalid

## 1. Requirements

- PHP **8.1+** (the package uses `readonly` properties; PHP 8.0 is not supported)
- Laravel **9.x, 10.x, 11.x, or 12.x** (Composer resolves `illuminate/*` and Symfony to the versions your app uses)
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

# Optional: when the access token is dead, redirect browser to SPA login instead of /sso/spa/redirect
# USJNET_SSO_INVALID_SESSION_REDIRECT=frontend

# Optional: override GET path used to verify token is still valid (default /api/user)
# USJNET_SSO_TOKEN_VALIDATION_PATH=/api/user
```

Register at the SSO server:

- **Redirect URI** = exact value of `USJNET_SSO_REDIRECT_URI` (same host as `APP_URL` + path `/sso/spa/callback`).

Use **one hostname style** everywhere (`127.0.0.1` **or** `localhost`, not mixed).

Default scope is **`view-user`** if you do not change it.

### Auth user mode (`sso` vs `system`)

After the SSO access token is validated, Laravel’s `Auth::user()` can be wired in two ways (see `config/usjnet-sso.php`):

| Mode | Behavior |
|------|----------|
| **`sso`** (default) | `Auth::user()` is an **`Illuminate\Auth\GenericUser`** built from the SSO `/api/user` JSON. |
| **`system`** | `Auth::user()` is your **`users` table Eloquent model**, found by **email** (SSO JSON key defaults to `email`). No local row → **403** unless you enable auto-create. |

**`.env` examples — system user (match `users.email` to SSO `email`):**

```env
USJNET_SSO_AUTH_USER_MODE=system
USJNET_SSO_SYSTEM_USER_MODEL=App\Models\User
USJNET_SSO_SYSTEM_USER_EMAIL_ATTRIBUTE=email
USJNET_SSO_SYSTEM_USER_EMAIL_COLUMN=email
USJNET_SSO_SYSTEM_USER_EMAIL_CI=true
# Optional: auto-provision local users (random password; SSO-only sign-in)
# USJNET_SSO_CREATE_SYSTEM_USER_IF_MISSING=true
# USJNET_SSO_SYSTEM_USER_NAME_ATTRIBUTES=name,username
```

`request()->attributes->get('sso_user')` is **always** the raw SSO profile array (for auditing or merging fields), even in `system` mode.

---

## 4. Run setup

```bash
php artisan vendor:publish --tag=usjnet-sso-config
php artisan usjnet-sso:install
php artisan usjnet-sso:doctor
```

`usjnet-sso:install` walks through **project style** (`separate` = SPA + API on different origins, `single` = one Laravel app), **OAuth client id/secret**, **Auth user mode** (`sso` vs `system`), **optional `USJNET_SSO_WEB_MIDDLEWARE_ALIAS`** (e.g. `auth`), **`USJNET_SSO_FRONTEND_HOME_URL`** (for `single`, default `{APP_URL}/home`; for `separate`, your SPA origin), then cookie/CORS keys into `.env`.

- Use a **real terminal** (TTY). With **`--no-interaction`** or some IDE runners, Laravel skips prompts: auth mode defaults to **`sso`** and a **warning** is shown — set **`USJNET_SSO_AUTH_USER_MODE`** in `.env` or run `php artisan usjnet-sso:install --auth-mode=system`. The web middleware alias question is skipped unless you pass **`--web-middleware-alias=auth`** (or another valid name).
- **No `.env` file** (e.g. API project not copied from `.env.example` yet): the installer **does not write** anything; it **warns** and prints a **copy-paste block** of the variables you just entered. Create `.env`, paste (or run install again), then `php artisan config:clear`.
- **`.env` exists but `USJNET_SSO_*` keys are missing**: Laravel still boots; `config/usjnet-sso.php` resolves those settings to **empty/null** (or the small **defaults** baked into that file, such as scope `view-user`). **SSO will not work** until `USJNET_SSO_BASE_URL`, client id/secret, redirect URI, and the rest are set — `php artisan usjnet-sso:doctor` will **FAIL** on the empty checks. You can **declare or change** any key in `.env` at any time (normal override); then run **`php artisan config:clear`** (and rebuild **`config:cache`** if your deployment uses it).
- Confirm Composer picked up this package: `composer show usjnet/laravel-sso` then `composer update usjnet/laravel-sso` (or refresh your path / VCS dependency).

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

**Laravel 9 or 10** (`app/Http/Middleware/EncryptCookies.php`): add the same names to the `$except` array property on `EncryptCookies`.

The package registers `sso.token` and `sso.web` automatically. Optionally set **`USJNET_SSO_WEB_MIDDLEWARE_ALIAS=auth`** in `.env` to also register **`auth`** as the same middleware (then you can use `Route::middleware('auth')`). Remove Laravel’s default **`auth`** → `Authenticate` alias if both exist and the wrong one wins (depends on service provider order).

## 6. Use in your app

### Web routes

If you prefer the familiar name **`auth`**, set in `.env`:

```env
USJNET_SSO_WEB_MIDDLEWARE_ALIAS=auth
```

Then run `php artisan config:clear`. **`sso.web` remains available** as an alias to the same middleware.

If your new project has backend-rendered pages (Blade/Inertia/etc.), protect those routes with:

```php
Route::middleware('auth')->group(function (): void {
    Route::view('/home', 'home');
});
```

Or keep using the package name:

```php
Route::middleware('sso.web')->group(function (): void {
    Route::view('/home', 'home');
});
```

Behavior for **`sso.web`** (only on routes where you attach it):

- if SSO access cookie is missing: redirect to `/sso/spa/redirect?state=...`
- if cookie exists but token is invalid: package performs SSO logout, clears local session/cookies, then redirects with `prompt_login=1` (or JSON **401** when `Accept: application/json` / `expectsJson()`)
- if token is valid: the package calls the SSO validation URL (default **`GET /api/user`**) with the access token; if that fails, it **logs out locally** (all Laravel guards + session), clears SSO cookies, then redirects to OAuth re-login or your SPA (see **`USJNET_SSO_INVALID_SESSION_REDIRECT`**).

### Live SSO check on every `web` request (`sso.web.live`)

If **`USJNET_SSO_VERIFY_LIVE_ON_WEB_GROUP`** is **true** (default in package config), **`VerifySsoAccessTokenLive`** is **appended to Laravel’s `web` middleware group**. Then any full-page request that **sends the SSO access cookie** (and is not on an OAuth or exempt path) re-validates the token with the IdP, so **logout or revocation in another app** is reflected here on the **next refresh or navigation**. The IdP is called **at most once per HTTP request** even if both **`sso.web.live`** and **`sso.web`** run (shared cache on the request). XHR/JSON clients get **401** with the same message as **`sso.token`**.

- Set **`USJNET_SSO_VERIFY_LIVE_ON_WEB_GROUP=false`** to disable the extra IdP round-trip on every `web` request (e.g. high traffic).
- **SPA-only traffic** to **`api`** routes must still use **`sso.token`** on those routes; the `web` live middleware does not run on the `api` stack unless you add it there yourself.

You may also attach the middleware manually: **`Route::middleware('sso.web.live')`** (do not duplicate if it is already pushed onto `web`).

### Invalid-session cleanup

Both middleware paths now enforce hard cleanup on invalid/expired sessions:

- **`sso.web`** (or your **`USJNET_SSO_WEB_MIDDLEWARE_ALIAS`**, e.g. **`auth`**): remote SSO logout (best effort), local session invalidation, cookie cleanup, redirect to login (or JSON **401** when appropriate)
- **`sso.web.live`**: same when an SSO cookie is present; **no-op** when there is no cookie (guest pages stay guest)
- **`sso.token`**: same cleanup + JSON `401` (`Session expired or invalid. Please login again.`)

This helps enforce global logout behavior across connected apps when SSO token is no longer valid.

### Access user in controllers

After `sso.web`, **`sso.web.live`** (when it runs), your configured web alias (e.g. `auth`), or `sso.token` middleware runs, all of these work:

```php
$request->user();        // GenericUser (sso mode) or App\Models\User (system mode)
$request->attributes->get('sso_user'); // always: raw array from SSO /api/user
Auth::user();
Auth::id();
Auth::guard('api')->user(); // when "api" is in auth.php (default priming includes web, api, sanctum if defined)
```

If you use a custom guard name, set **`USJNET_SSO_AUTH_GUARDS=web,your_guard`** in `.env` (see `config/usjnet-sso.php`).

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
    Route::view('/dashboard', 'dashboard');
});
```

Rule:

- **Package** = authentication. After `sso.web` / `sso.token`, `Auth::user()` is set on the default guard and on **`web`**, **`api`**, and **`sanctum`** (if that guard exists). With **`USJNET_SSO_AUTH_USER_MODE=sso`** (default), that user is a **`GenericUser`** from SSO JSON; with **`system`**, it is your **`User`** model matched by email. SSO JSON is always on **`request()->attributes->get('sso_user')`**. Override guards with **`USJNET_SSO_AUTH_GUARDS`** in `.env` (comma-separated), e.g. `web,custom`.
- **App** = authorization / business rules

### Dual web login: SSO and local session (e.g. `/admin/login`)

**Preferred:** point at your **local login route** (no leading slash). SSO middleware skips that URL and every path **under the same parent** as that login page — so `/admin/login` also exempts `/admin`, `/admin/dashboard`, etc.

```env
USJNET_SSO_WEB_LOCAL_LOGIN_PATHS=admin/login
```

Comma-separate multiple logins (`admin/login,staff/login`). SSO-backed routes elsewhere still require a valid SSO cookie as usual.

**Alternative:** exempt a URL prefix without naming a login path:

```env
USJNET_SSO_WEB_EXEMPT_PREFIXES=admin
```

If **`sso.web`** is attached only to **SSO-backed route groups**, you can omit these keys and register **`/admin/*`** outside those groups instead.

## 11. Avoid duplicate routes

If you already defined `/sso/spa/*` or `/api/auth/*` in your app, remove the duplicates or uninstall in-house copies before installing this package.

---

## 12. Troubleshooting

| Issue | Check |
|-------|--------|
| `login_error=session_expired` | SSO access token failed validation on a web request. Set **`USJNET_SSO_INVALID_SESSION_REDIRECT=frontend`** to land on **`USJNET_SSO_FRONTEND_HOME_URL`** instead of `/sso/spa/redirect`. Ensure protected routes use **`sso.web`** / **`auth`** (your alias). |
| `token_exchange_failed` + **Client authentication failed** | OAuth **client_id** / **client_secret** do not match the SSO server (wrong secret, typo, or `.env` not reloaded). Re-copy the secret from the SSO admin UI; use `php artisan config:clear`. Client must be **confidential** if you send **client_secret**. **redirect_uri** in token request must exactly match the one used in `/oauth/authorize` and SSO registration. |
| `invalid_state` | Same tab session; one redirect from SPA; `APP_URL` matches cookie domain |
| `invalid_grant` | `USJNET_SSO_REDIRECT_URI` matches SSO registration and token exchange |
| 401 on `/api/auth/me` | `Authorization: Bearer` or HttpOnly cookie; `withCredentials` on SPA |
| Package not loading | `composer dump-autoload`; clear config cache |
| Installer said success but `.env` has no SSO keys | Older behaviour: if **`.env` is missing**, the installer now **warns** and prints a block instead of claiming success. Create `.env` and paste or re-run. |
| SSO doctor fails “not configured” on API | Add **`USJNET_SSO_*`** (and **`CORS_ALLOWED_ORIGINS`**) to that app’s **`.env`**, or merge from the installer output — empty env means null config and broken OAuth until keys exist. |
| 301/302 loop / “too many redirects” | Do not require an access cookie on OAuth routes: this package skips **`sso/spa/redirect`** and **`sso/spa/callback`** inside `sso.web`. If you customized paths, set **`web_sso_public_paths`** in `config/usjnet-sso.php`. Prefer attaching **`sso.web` only to protected route groups**, not the entire `web` stack. |
| Still “logged in” here after SSO logout elsewhere | Ensure **`USJNET_SSO_VERIFY_LIVE_ON_WEB_GROUP=true`** (default) so **`sso.web.live`** runs on the **`web`** stack; use **`sso.token`** on **`api`** routes. If the IdP still returns **200** for **`USJNET_SSO_TOKEN_VALIDATION_PATH`** until JWT expiry, shorten access-token TTL or use an endpoint that reflects revocation. |
| 403 `no_local_account` (system mode) | SSO email must match a row in `users` (or set `USJNET_SSO_CREATE_SYSTEM_USER_IF_MISSING=true` to auto-provision). |
| Composer: `laravel/pint` needs PHP ^8.2 | In your **app** `composer.json`, cap Pint to the last PHP 8.1–compatible line, e.g. `"laravel/pint": "^1.18.3 <1.21"` (1.21+ requires PHP 8.2), then `composer update laravel/pint -W` |
| Composer: `dragonmantank/cron-expression` needs PHP ^8.2 | In your **app** `composer.json`, add `"dragonmantank/cron-expression": "^3.3.2,<3.6"` (3.6+ requires PHP 8.2), then `composer update dragonmantank/cron-expression -W` |

On **PHP 8.1** with **Laravel 9**, you may need both pins above so the rest of your app’s dev tooling and scheduler dependencies stay installable.

---

## 13. License

MIT (align with your organization’s policy before publishing).
