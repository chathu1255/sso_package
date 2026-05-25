<?php

namespace Usjnet\Sso\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Auth\SessionGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * Local Laravel login (e.g. /admin/login) separate from SSO.
 *
 * - Guests: only configured login entry URLs skip SSO (not the whole /admin tree).
 * - After login: configured guard authenticated → skip SSO on every path in the app.
 */
final class LocalLoginZone
{
    /** @var list<string> Guards registered at runtime because they were missing from config/auth.php */
    private static array $autoRegisteredGuards = [];

    /**
     * @return list<string>
     */
    public static function autoRegisteredGuards(): array
    {
        return self::$autoRegisteredGuards;
    }

    /**
     * Register missing local-login guards in runtime config (session driver + existing user provider).
     *
     * @return list<string> Guard names that were auto-registered this boot
     */
    public static function registerMissingGuards(): array
    {
        if (filter_var(config('usjnet-sso.auto_register_local_login_guards'), FILTER_VALIDATE_BOOLEAN) === false) {
            return [];
        }

        if (self::loginPaths() === []) {
            return [];
        }

        self::$autoRegisteredGuards = [];
        $provider = self::resolveProviderForAutoGuard();

        if ($provider === null) {
            return [];
        }

        foreach (self::bypassGuards() as $guardName) {
            if (array_key_exists($guardName, config('auth.guards', []))) {
                continue;
            }

            config([
                'auth.guards.'.$guardName => [
                    'driver' => 'session',
                    'provider' => $provider,
                ],
            ]);

            self::$autoRegisteredGuards[] = $guardName;
        }

        return self::$autoRegisteredGuards;
    }

    public static function resolveProviderForAutoGuard(): ?string
    {
        $providers = config('auth.providers', []);
        if (! is_array($providers)) {
            return null;
        }

        $webProvider = config('auth.guards.web.provider');
        if (is_string($webProvider) && $webProvider !== '' && array_key_exists($webProvider, $providers)) {
            return $webProvider;
        }

        if (array_key_exists('users', $providers)) {
            return 'users';
        }

        if (array_key_exists('admins', $providers)) {
            return 'admins';
        }

        $keys = array_keys($providers);

        return isset($keys[0]) && is_string($keys[0]) ? $keys[0] : null;
    }

    /**
     * @return list<string>
     */
    public static function loginPaths(): array
    {
        $paths = config('usjnet-sso.web_sso_local_login_paths', []);

        if (! is_array($paths)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $p): string => mb_strtolower(trim(str_replace('\\', '/', (string) $p), '/')),
            $paths
        )));
    }

    /**
     * Optional extra guest-only paths (exact or prefix) that skip SSO without being logged in.
     * Does not apply after login; use guards for that. Comma-separated USJNET_SSO_WEB_EXEMPT_PREFIXES.
     *
     * @return list<string>
     */
    public static function guestOnlyExemptPrefixes(): array
    {
        $prefixes = config('usjnet-sso.web_sso_exempt_path_prefixes', []);

        if (! is_array($prefixes)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $p): string => mb_strtolower(trim(str_replace('\\', '/', (string) $p), '/')),
            $prefixes
        ))));
    }

    /**
     * @return list<string>
     */
    public static function bypassGuards(): array
    {
        $guards = [];

        $fromConfig = config('usjnet-sso.web_sso_local_login_guards', []);
        if (is_array($fromConfig)) {
            foreach ($fromConfig as $g) {
                if (is_string($g) && trim($g) !== '') {
                    $guards[] = trim($g);
                }
            }
        }

        $legacy = config('usjnet-sso.skip_sso_web_checks_when_guard');
        if (is_string($legacy) && trim($legacy) !== '') {
            $guards[] = trim($legacy);
        }

        $defined = array_keys(config('auth.guards', []));
        foreach (self::loginPaths() as $loginPath) {
            $parent = dirname($loginPath);
            if ($parent === '.' || $parent === '/') {
                continue;
            }
            $parent = trim(str_replace('\\', '/', $parent), '/');
            if ($parent !== '' && in_array($parent, $defined, true)) {
                $guards[] = $parent;
            }
        }

        return array_values(array_unique($guards));
    }

    public static function requestPath(Request $request): string
    {
        return mb_strtolower(trim((string) $request->path(), '/'));
    }

    /**
     * Guest may open the login form (and optional guest-only paths) without SSO redirect.
     */
    public static function isLocalLoginEntryPath(Request $request): bool
    {
        $path = self::requestPath($request);

        foreach (self::loginPaths() as $loginPath) {
            if ($path === $loginPath) {
                return true;
            }
        }

        foreach (self::guestOnlyExemptPrefixes() as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * After local login: no SSO checks on any URL while the configured guard (e.g. admin) is authenticated.
     */
    public static function hasActiveLocalGuardSession(Request $request): bool
    {
        foreach (self::bypassGuards() as $guardName) {
            try {
                if (Auth::guard($guardName)->check()) {
                    return true;
                }
            } catch (Throwable) {
                //
            }

            if (self::sessionHasGuardLoginRecord($request, $guardName)) {
                return true;
            }
        }

        $sessionKey = config('usjnet-sso.skip_sso_web_checks_when_session_key');
        if (! is_string($sessionKey) || trim($sessionKey) === '') {
            return false;
        }

        if (! $request->hasSession()) {
            return false;
        }

        return (bool) $request->session()->get(trim($sessionKey));
    }

    /**
     * @return array{guard: string, user: Authenticatable}|null
     */
    public static function resolveActiveLocalGuardUser(Request $request): ?array
    {
        foreach (self::bypassGuards() as $guardName) {
            try {
                $guard = Auth::guard($guardName);
                $user = $guard->user();

                if ($user instanceof Authenticatable) {
                    return [
                        'guard' => $guardName,
                        'user' => $user,
                    ];
                }
            } catch (Throwable) {
                //
            }

            if (! self::sessionHasGuardLoginRecord($request, $guardName)) {
                continue;
            }

            try {
                $user = Auth::guard($guardName)->user();
                if ($user instanceof Authenticatable) {
                    return [
                        'guard' => $guardName,
                        'user' => $user,
                    ];
                }
            } catch (Throwable) {
                //
            }
        }

        return null;
    }

    public static function shouldSkipSsoWebMiddleware(Request $request): bool
    {
        return self::isLocalLoginEntryPath($request)
            || self::hasActiveLocalGuardSession($request);
    }

    /**
     * Detect Laravel session login for a guard even if Auth::guard()->check() is not ready yet this request.
     */
    public static function sessionHasGuardLoginRecord(Request $request, string $guardName): bool
    {
        if (! $request->hasSession()) {
            return false;
        }

        $session = $request->session();
        $sessionKey = 'login_'.$guardName.'_'.sha1(SessionGuard::class);

        if ($session->has($sessionKey)) {
            return true;
        }

        $provider = config('auth.guards.'.$guardName.'.provider');
        if (! is_string($provider) || $provider === '') {
            return false;
        }

        $recaller = 'remember_'.$guardName.'_'.sha1($provider);

        return $session->has($recaller);
    }
}
