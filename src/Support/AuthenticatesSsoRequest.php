<?php

namespace Usjnet\Sso\Support;

use Illuminate\Auth\GenericUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Throwable;

trait AuthenticatesSsoRequest
{
    /**
     * Attach SSO profile to the request and Laravel auth so Auth::user(), Auth::id(),
     * and $request->user() work for typical web + API setups.
     *
     * @param  array<string, mixed>  $ssoUser
     */
    protected function authenticateSsoRequest(Request $request, array $ssoUser): GenericUser
    {
        $attributes = $this->normalizeSsoUserForAuth($ssoUser);
        $authUser = new GenericUser($attributes);

        $request->attributes->set('sso_user', $ssoUser);
        $request->setUserResolver(static function ($guard = null) use ($authUser): GenericUser {
            return $authUser;
        });

        $this->primeAuthGuardsWithUser($authUser);

        return $authUser;
    }

    /**
     * GenericUser::getAuthIdentifier() reads the "id" key; SSO payloads often use sub/user_id only.
     *
     * @param  array<string, mixed>  $ssoUser
     * @return array<string, mixed>
     */
    protected function normalizeSsoUserForAuth(array $ssoUser): array
    {
        $user = $ssoUser;

        $id = $user['id'] ?? null;
        $hasId = $id !== null && $id !== '';

        if (! $hasId) {
            foreach (['user_id', 'sub', 'uuid', 'employeeNumber', 'accountCode'] as $key) {
                if (! empty($user[$key])) {
                    $user['id'] = $user[$key];
                    $hasId = true;
                    break;
                }
            }
        }

        if (! $hasId) {
            $email = isset($user['email']) ? (string) $user['email'] : '';
            if ($email !== '') {
                $user['id'] = $email;
            } else {
                $user['id'] = 'sso:'.substr(md5((string) json_encode($user)), 0, 32);
            }
        }

        return $user;
    }

    protected function primeAuthGuardsWithUser(GenericUser $authUser): void
    {
        $defined = array_keys(config('auth.guards', []));

        $configured = config('usjnet-sso.auth_guards');
        if (is_array($configured) && $configured !== []) {
            $guardNames = $configured;
        } else {
            $default = config('auth.defaults.guard');
            $guardNames = array_filter([
                is_string($default) ? $default : null,
                'web',
                'api',
            ]);
            if (in_array('sanctum', $defined, true)) {
                $guardNames[] = 'sanctum';
            }
        }

        $guardNames = array_values(array_unique(array_filter(
            is_array($guardNames) ? $guardNames : [],
            static fn ($name): bool => is_string($name) && $name !== ''
        )));

        $primed = false;
        foreach ($guardNames as $guardName) {
            if (! in_array($guardName, $defined, true)) {
                continue;
            }
            try {
                $guard = Auth::guard($guardName);
                if (method_exists($guard, 'setUser')) {
                    $guard->setUser($authUser);
                    $primed = true;
                }
            } catch (Throwable) {
                // Custom / misconfigured guards: skip.
            }
        }

        if (! $primed) {
            try {
                Auth::setUser($authUser);
            } catch (Throwable) {
                //
            }
        }
    }
}
