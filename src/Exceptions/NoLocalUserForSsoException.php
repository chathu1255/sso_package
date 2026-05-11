<?php

namespace Usjnet\Sso\Exceptions;

use RuntimeException;

class NoLocalUserForSsoException extends RuntimeException
{
    public static function missingEmail(): self
    {
        return new self('SSO profile has no usable email; cannot match a local user.');
    }

    public static function noAccount(): self
    {
        return new self('No local user account exists for this SSO identity. Ask an administrator to create your user or enable USJNET_SSO_CREATE_SYSTEM_USER_IF_MISSING.');
    }
}
