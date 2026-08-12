<?php

declare(strict_types=1);

namespace App\Platform\Payment\Contracts;

use App\Platform\IdentityAccess\ActorContext;
use App\Platform\Payment\Exceptions\PaymentActionNotAuthorisedException;

/**
 * The authorization policy for the two money-moving payment admin actions —
 * recording a reversal (refund/chargeback) and deciding a manual payment
 * verification.
 *
 * Deliberately narrower than the four authorizer contracts in
 * `FinancialLedger\Contracts`: there is NO record-scope parameter. Those
 * four authorizers take a `$vendorId` / `$entityRef` because the records
 * they guard genuinely are scoped; `payment_reversals` and
 * `payment_verifications` carry no column in the
 * `scope_assignments.entity_id` value space at all.
 * See `FinanceOrRestrictedAdminPaymentAuthorizer`'s class-level doc block
 * for the full schema argument and for why scoping on the one candidate
 * column would be strictly worse than not scoping.
 *
 * Implementations return the approved role for the SERVER-RESOLVED actor, or
 * throw. The returned role is what the caller must record in the audit trail
 * — never a caller-supplied role string, and never the `authenticated_actor`
 * sentinel, which `Roles\ActorRole` defines as meaning "no role applies."
 */
interface PaymentActionAuthorizer
{
    /**
     * Return the approved role for the server-resolved actor, or refuse.
     *
     * An implementation must never read caller-supplied identity or role
     * data: `$actor` comes from `ActorContextResolver`, which resolves it
     * once per request from the authenticated guard.
     *
     * @throws PaymentActionNotAuthorisedException
     */
    public function authorize(ActorContext $actor): string;
}
