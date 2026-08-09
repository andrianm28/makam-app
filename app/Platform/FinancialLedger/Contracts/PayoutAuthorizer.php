<?php

declare(strict_types=1);

namespace App\Platform\FinancialLedger\Contracts;

use App\Platform\IdentityAccess\ActorContext;

interface PayoutAuthorizer
{
    /**
     * Return the approved role for the server-resolved actor, or refuse.
     *
     * The vendor identifier is record scope, not an actor selector. An
     * implementation must never use caller-supplied identity or role data.
     */
    public function authorize(ActorContext $actor, string $vendorId): string;
}
