<?php

declare(strict_types=1);

namespace App\Platform\IdentityAccess\Reauthentication\Exceptions;

use RuntimeException;

final class ReauthenticationRequiredException extends RuntimeException
{
    public static function forActor(): self
    {
        return new self('A recent re-authentication is required before this action may be performed.');
    }
}
