<?php

declare(strict_types=1);

namespace App\Domain\OrderWorkflow\Authorization\Contracts;

use App\Domain\OrderWorkflow\Exceptions\OrderActionNotAuthorisedException;
use App\Platform\IdentityAccess\ActorContext;

interface OrderTransitionAuthorizerContract
{
    /**
     * @param  ?string  $cemeteryId  The cemetery the record being
     *                               transitioned belongs to, when the caller
     *                               has one to resolve — `null` for records
     *                               with no cemetery concept (e.g.
     *                               `MarketplaceOrder`). Required for a
     *                               `cemetery_operator` actor to ever be
     *                               authorized; every other role's
     *                               authorization is unaffected by this
     *                               parameter.
     *
     * @throws OrderActionNotAuthorisedException
     */
    public function authorizeTransition(ActorContext $actor, string $transition, ?string $cemeteryId = null): void;
}
