<?php

declare(strict_types=1);

namespace App\Platform\FinancialLedger\Contracts;

use App\Platform\FinancialLedger\Exceptions\ReconciliationNotAuthorisedException;
use App\Platform\IdentityAccess\ActorContext;

/**
 * The policy seam for "may this actor DECIDE a reconciliation exception for
 * this badan usaha".
 *
 * ---------------------------------------------------------------------------
 * Deliberately NOT `PayoutAuthorizer`, and not a widening of it
 * ---------------------------------------------------------------------------
 * The two look alike on purpose — same server-side-actor discipline, same
 * "return the approved role or refuse" shape — but they are different
 * permissions and must not become one:
 *
 *  - a payout moves money OUT of the business to a named VENDOR;
 *  - a reconciliation decision closes a finding about a BADAN USAHA's books.
 *
 * They are scoped to different `ScopeEntityType` values over different id
 * spaces, and merging them would silently grant payout rights to everyone who
 * can accept a variance. Task 5's brief is explicit that if an implementer
 * concludes they should be the same permission, that is a decision to report,
 * not one to take — so this interface exists rather than a second
 * `authorize()` overload on the payout one.
 *
 * ---------------------------------------------------------------------------
 * Contract terms an implementation must keep
 * ---------------------------------------------------------------------------
 *  1. The deciding actor is derived from the authenticated server-side
 *     `ActorContext`. Caller-supplied references or roles must never select an
 *     actor or grant a role (Wave 1b ruling, same as `ManualPayout`).
 *  2. An empty role list is not permission. Absence of information fails
 *     closed, never open.
 *  3. `$entityRef` is RECORD SCOPE, not an actor selector.
 */
interface ReconciliationAuthorizer
{
    /**
     * Return the approved role for the server-resolved actor, or refuse.
     *
     * @throws ReconciliationNotAuthorisedException
     */
    public function authorize(ActorContext $actor, string $entityRef): string;
}
