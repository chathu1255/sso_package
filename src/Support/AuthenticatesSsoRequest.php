<?php

namespace Usjnet\Sso\Support;

use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;
use Usjnet\Sso\Exceptions\NoLocalUserForSsoException;

trait AuthenticatesSsoRequest
{
    /**
     * Attach SSO profile to the request and Laravel auth so Auth::user(), Auth::id(),
     * and $request->user() work for typical web + API setups.
     *
     * @param  array<string, mixed>  $ssoUser
     */
    protected function authenticateSsoRequest(Request $request, array $ssoUser): Authenticatable
    {
        $request->attributes->set('sso_user', $ssoUser);

        $authUser = $this->resolveAuthenticatableFromSso($ssoUser);

        $request->setUserResolver(static function ($guard = null) use ($authUser): Authenticatable {
            return $authUser;
        });

        $this->primeAuthGuardsWithUser($authUser);

        return $authUser;
    }

    /**
     * @param  array<string, mixed>  $ssoUser
     */
    protected function resolveAuthenticatableFromSso(array $ssoUser): Authenticatable
    {
        $mode = strtolower(trim((string) config('usjnet-sso.auth_user_mode', 'sso')));

        if ($mode === 'system') {
            return $this->resolveSystemDatabaseUser($ssoUser);
        }

        $attributes = $this->normalizeSsoUserForAuth($ssoUser);

        return new GenericUser($attributes);
    }

    /**
     * @param  array<string, mixed>  $ssoUser
     */
    protected function resolveSystemDatabaseUser(array $ssoUser): Authenticatable
    {
        $modelClass = (string) config('usjnet-sso.system_user_model', 'App\\Models\\User');

        if (! class_exists($modelClass)) {
            throw new InvalidArgumentException("usjnet-sso.system_user_model class does not exist: {$modelClass}");
        }

        if (! is_subclass_of($modelClass, Model::class)) {
            throw new InvalidArgumentException("usjnet-sso.system_user_model must extend Eloquent Model: {$modelClass}");
        }

        if (! is_a($modelClass, Authenticatable::class, true)) {
            throw new InvalidArgumentException("usjnet-sso.system_user_model must implement Authenticatable: {$modelClass}");
        }

        $emailKey = (string) config('usjnet-sso.system_user_email_attribute', 'email');
        $email = trim((string) data_get($ssoUser, $emailKey, ''));

        if ($email === '') {
            throw NoLocalUserForSsoException::missingEmail();
        }

        $column = (string) config('usjnet-sso.system_user_email_column', 'email');
        if (! preg_match('/^[a-zA-Z0-9_]+$/', $column)) {
            throw new InvalidArgumentException('usjnet-sso.system_user_email_column must be alphanumeric/underscore only.');
        }

        $ci = (bool) config('usjnet-sso.system_user_match_case_insensitive', true);

        /** @var Model $modelInstance */
        $modelInstance = new $modelClass;
        $query = $modelClass::query();

        if ($ci) {
            $grammar = $query->getGrammar();
            $qualified = $modelInstance->qualifyColumn($column);
            $wrapped = $grammar->wrap($qualified);
            $query->whereRaw('LOWER('.$wrapped.') = ?', [mb_strtolower($email)]);
        } else {
            $query->where($column, $email);
        }

        $dbUser = $query->first();

        if ($dbUser instanceof Authenticatable) {
            return $dbUser;
        }

        if (! (bool) config('usjnet-sso.create_system_user_if_missing', false)) {
            throw NoLocalUserForSsoException::noAccount();
        }

        return $this->createSystemUserFromSso($modelClass, $email, $ssoUser, $column);
    }

    /**
     * @param  class-string<Model&Authenticatable>  $modelClass
     * @param  array<string, mixed>  $ssoUser
     */
    protected function createSystemUserFromSso(string $modelClass, string $email, array $ssoUser, string $emailColumn): Authenticatable
    {
        $name = $this->guessSystemUserDisplayName($ssoUser);
        if ($name === '') {
            $name = $email;
        }

        $password = Hash::make(Str::random(64));

        $data = array_filter([
            $emailColumn => $email,
            'name' => $name,
            'password' => $password,
        ], static fn ($v) => $v !== null && $v !== '');

        /** @var Model&Authenticatable $user */
        $user = new $modelClass;
        $user->forceFill($data);
        $user->save();

        return $user;
    }

    /**
     * @param  array<string, mixed>  $ssoUser
     */
    protected function guessSystemUserDisplayName(array $ssoUser): string
    {
        $keys = config('usjnet-sso.system_user_name_attributes', ['name', 'username']);
        if (! is_array($keys)) {
            $keys = ['name', 'username'];
        }

        foreach ($keys as $key) {
            if (! is_string($key) || $key === '') {
                continue;
            }
            $v = data_get($ssoUser, $key);
            if (is_string($v) && trim($v) !== '') {
                return trim($v);
            }
        }

        return '';
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

    protected function primeAuthGuardsWithUser(Authenticatable $authUser): void
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
