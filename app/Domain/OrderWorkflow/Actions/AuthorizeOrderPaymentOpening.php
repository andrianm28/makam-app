<?php

declare(strict_types=1);

namespace App\Domain\OrderWorkflow\Actions;

use App\Domain\OrderWorkflow\Exceptions\OrderPaymentOpeningNotAuthorisedException;
use App\Domain\OrderWorkflow\Models\Order;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\Roles\ActorRole;
use App\Platform\IdentityAccess\Scopes\Models\ScopeAssignment;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;

/**
 * Condition 4 of the payment guard: the actor holds opening permission for
 * the order.
 *
 * Mechanism, copied from `FinanceOrRestrictedAdminPayoutAuthorizer`:
 * resolve roles from `ActorContext`, then an explicit `ScopeAssignment`
 * existence check against `ScopeEntityType::ORDER` (already a reserved value),
 * `entity_id` = the order id, `actor_identifier` = the actor's string
 * identity reference, `revoked_at` null.
 *
 * No `grant_level` requirement — the plan's wording is "existence check"
 * and the ORDER scope has no privileged/own semantics ratified yet.
 */
final readonly class AuthorizeOrderPaymentOpening
{
    /** @var list<string> */
    private const array AUTHORISED_ROLES = [ActorRole::ADMIN];

    public function __invoke(ActorContext $actor, Order $order): string
    {
        $role = $this->roleFromContext($actor);

        if ($role === null) {
            throw OrderPaymentOpeningNotAuthorisedException::forOrder($order->getKey());
        }

        $hasOrderGrant = ScopeAssignment::query()
            ->where('actor_identifier', (string) $actor->identityReference)
            ->where('entity_type', ScopeEntityType::ORDER)
            ->where('entity_id', $order->getKey())
            ->whereNull('revoked_at')
            ->exists();

        if (! $hasOrderGrant) {
            throw OrderPaymentOpeningNotAuthorisedException::forOrder($order->getKey());
        }

        return $role;
    }

    private function roleFromContext(ActorContext $actor): ?string
    {
        foreach (self::AUTHORISED_ROLES as $role) {
            if (in_array($role, $actor->roles, true)) {
                return $role;
            }
        }

        return null;
    }
}
