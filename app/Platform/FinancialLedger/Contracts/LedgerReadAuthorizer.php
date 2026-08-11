<?php

declare(strict_types=1);

namespace App\Platform\FinancialLedger\Contracts;

use App\Platform\FinancialLedger\Exceptions\LedgerReadNotAuthorisedException;
use App\Platform\FinancialLedger\LedgerReadScope;
use App\Platform\IdentityAccess\ActorContext;

/**
 * The policy seam for "may this actor READ the financial ledger, and for which
 * badan usaha".
 *
 * ---------------------------------------------------------------------------
 * Why a read needs its own authorizer at all
 * ---------------------------------------------------------------------------
 * A bulk export is a read, and the two existing authorizers in this module
 * (`PayoutAuthorizer`, `ReconciliationAuthorizer`) both gate a WRITE. It would
 * have been easy to conclude a read needs no policy — that is exactly the
 * reasoning that left `GET /admin/finance/exports` reachable by any
 * authenticated account. One export returns every account total for every
 * badan usaha in the deployment; that is at least as sensitive as deciding a
 * single variance, which this module already gates hard.
 *
 * ---------------------------------------------------------------------------
 * Contract terms an implementation must keep
 * ---------------------------------------------------------------------------
 *  1. The reading actor is derived from the authenticated server-side
 *     `ActorContext`. A caller-supplied actor reference or role must never
 *     select an actor or grant a role (Wave 1b ruling, same as `ManualPayout`
 *     and `ResolveException`).
 *  2. An empty role list is not permission. Absence of information fails
 *     closed, never open.
 *  3. `$entityRef` is a caller-supplied REQUEST for a narrower scope. It may
 *     only ever narrow what the actor's own grants already permit, and must be
 *     refused — not silently ignored, and not silently widened — when it names
 *     a badan usaha the actor holds no grant for.
 *  4. The returned `LedgerReadScope::$entityRefs` is non-empty. "This actor
 *     may read nothing" is a refusal, never an empty filter.
 */
interface LedgerReadAuthorizer
{
    /**
     * Resolve the permitted read scope for the server-resolved actor, or
     * refuse.
     *
     * @param  string|null  $entityRef  An optional caller-requested narrowing
     *                                  to a single badan usaha. `null` means
     *                                  "everything this actor is granted".
     *
     * @throws LedgerReadNotAuthorisedException
     */
    public function authorize(ActorContext $actor, ?string $entityRef = null): LedgerReadScope;
}
