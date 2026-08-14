<?php

declare(strict_types=1);

namespace App\Platform\IdentityAccess\MasterData\Exceptions;

use RuntimeException;

/**
 * The authenticated actor is not authorized to administer the shared
 * master-data entities.
 *
 * Deliberately its own exception type: master-data administration is a
 * distinct permission from the financial authorizers' money actions, and
 * sharing a refusal type is the first step towards sharing a check. See
 * `App\Platform\IdentityAccess\MasterData\MasterDataAdminAuthorizer` for the
 * mechanism.
 *
 * Fail-closed: an empty role list means no authorisation, never
 * "unrestricted until someone configures it". The message is the same for a
 * guest and for an authorized-but-roleless actor, and names no identity
 * detail beyond the failure itself.
 */
final class MasterDataNotAuthorisedException extends RuntimeException
{
    public static function forActorContext(): self
    {
        return new self(
            'The authenticated actor has no back-office role granting master-data administration.'
        );
    }
}
