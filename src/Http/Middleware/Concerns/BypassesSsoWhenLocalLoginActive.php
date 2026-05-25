<?php

namespace Usjnet\Sso\Http\Middleware\Concerns;

use Illuminate\Http\Request;
use Usjnet\Sso\Support\LocalLoginZone;

trait BypassesSsoWhenLocalLoginActive
{
    protected function shouldBypassAllSsoChecks(Request $request): bool
    {
        return LocalLoginZone::hasActiveLocalGuardSession($request);
    }

    protected function isWebSsoExemptByConfiguredPaths(Request $request): bool
    {
        return LocalLoginZone::isLocalLoginEntryPath($request);
    }
}
