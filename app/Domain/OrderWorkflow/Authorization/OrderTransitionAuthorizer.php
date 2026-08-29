<?php

declare(strict_types=1);

namespace App\Domain\OrderWorkflow\Authorization;

use App\Domain\OrderWorkflow\Authorization\Contracts\OrderTransitionAuthorizerContract;
use App\Domain\OrderWorkflow\Exceptions\OrderActionNotAuthorisedException;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\Roles\ActorRole;
use App\Platform\IdentityAccess\Scopes\ScopeAssignmentResolver;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;

final class OrderTransitionAuthorizer implements OrderTransitionAuthorizerContract
{
    /** Transitions that create a binding quote — restricted_admin excluded. */
    private const array QUOTE_ISSUING_TRANSITIONS = ['issue_quote'];

    /** Transitions that touch money or authorize payment opening — finance/admin only. */
    private const array MONEY_TRANSITIONS = [
        'authorize_payment_opening',
        'manual_payment_verification',
        'mark_order_paid',
        'mark_marketplace_order_paid',
        'record_external_renewal_payment',
    ];

    public function __construct(
        private readonly ScopeAssignmentResolver $scopes,
    ) {}

    public function authorizeTransition(ActorContext $actor, string $transition, ?string $cemeteryId = null): void
    {
        if ($actor->identityReference === null || $actor->roles === []) {
            throw OrderActionNotAuthorisedException::forActorContext();
        }

        if (in_array(ActorRole::ADMIN, $actor->roles, true)) {
            return;
        }

        if (in_array($transition, self::MONEY_TRANSITIONS, true)) {
            if (in_array(ActorRole::FINANCE, $actor->roles, true)) {
                return;
            }

            throw OrderActionNotAuthorisedException::forTransition($transition);
        }

        if (in_array($transition, self::QUOTE_ISSUING_TRANSITIONS, true)
            && in_array(ActorRole::RESTRICTED_ADMIN, $actor->roles, true)) {
            throw OrderActionNotAuthorisedException::forTransition($transition);
        }

        if (in_array(ActorRole::OPERATOR, $actor->roles, true)
            || in_array(ActorRole::RESTRICTED_ADMIN, $actor->roles, true)) {
            return;
        }

        // TPU/TPS operator dashboard roadmap Phase A
        // (docs/superpowers/plans/2026-08-28-operator-panel-and-role.md,
        // Task 6): a cemetery_operator is authorized for a non-money
        // transition ONLY when the caller resolved a cemetery id AND that
        // id is among the actor's active cemetery grants. A MarketplaceOrder
        // call site passes no cemetery id at all (it has no cemetery
        // concept), so cemetery_operator can never pass this branch there —
        // correct, since marketplace orders are outside a cemetery
        // operator's remit.
        if (in_array(ActorRole::CEMETERY_OPERATOR, $actor->roles, true)
            && $cemeteryId !== null
            && in_array($cemeteryId, $this->scopes->grantedEntityIds($actor->identityReference, ScopeEntityType::CEMETERY), true)) {
            return;
        }

        throw OrderActionNotAuthorisedException::forActorContext();
    }
}
