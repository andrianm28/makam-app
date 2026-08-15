<?php

declare(strict_types=1);

namespace App\Domain\OrderWorkflow\Authorization;

use App\Domain\OrderWorkflow\Authorization\Contracts\OrderTransitionAuthorizerContract;
use App\Domain\OrderWorkflow\Exceptions\OrderActionNotAuthorisedException;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\Roles\ActorRole;

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

    public function authorizeTransition(ActorContext $actor, string $transition): void
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

        throw OrderActionNotAuthorisedException::forActorContext();
    }
}
