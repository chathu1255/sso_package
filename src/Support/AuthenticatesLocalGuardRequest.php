<?php

namespace Usjnet\Sso\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

trait AuthenticatesLocalGuardRequest
{
    protected function authenticateLocalGuardRequest(Request $request): bool
    {
        $active = LocalLoginZone::resolveActiveLocalGuardUser($request);

        if ($active === null) {
            return false;
        }

        $guardName = $active['guard'];
        $authUser = $active['user'];

        $request->setUserResolver(static function ($guard = null) use ($authUser, $guardName): ?Authenticatable {
            if (is_string($guard) && $guard !== '') {
                return Auth::guard($guard)->user();
            }

            return $authUser;
        });

        try {
            Auth::shouldUse($guardName);
        } catch (\Throwable) {
            //
        }

        try {
            Auth::setUser($authUser);
        } catch (\Throwable) {
            //
        }

        return true;
    }
}
