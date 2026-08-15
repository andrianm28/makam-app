<?php

declare(strict_types=1);

namespace App\Domain\OrderWorkflow\Authorization\Contracts;

use App\Domain\OrderWorkflow\Exceptions\OrderActionNotAuthorisedException;
use App\Platform\IdentityAccess\ActorContext;

interface OrderTransitionAuthorizerContract
{
    /**
     * @throws OrderActionNotAuthorisedException
     */
    public function authorizeTransition(ActorContext $actor, string $transition): void;
}
