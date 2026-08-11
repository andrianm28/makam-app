<?php

declare(strict_types=1);

namespace App\Platform\FinancialLedger\Contracts;

use App\Platform\FinancialLedger\Exceptions\VendorPayableNotAuthorisedException;
use App\Platform\IdentityAccess\ActorContext;

/**
 * The policy seam for "may this caller RECOGNISE a liability to this vendor".
 *
 * ---------------------------------------------------------------------------
 * Why this exists at all
 * ---------------------------------------------------------------------------
 * `Actions\VendorPayable::assess()` writes the append-only ledger — `accrue()`
 * posts `DR 5000 / CR 2100` through the shared `Journal` API. Until Task 9b it
 * was the ONLY path in this module that wrote the ledger with no authorization
 * of any kind: no `ActorContext`, no authorizer, no scope check, while its
 * sibling Actions (`ManualPayout`, `ResolveException`, `BulkFinancialExport`)
 * all take `ActorContext` first plus a policy seam. Its audit row recorded
 * `actorRole: 'system'` regardless of who actually triggered it, so a
 * human-triggered assessment produced an audit trail that asserted the system
 * did it.
 *
 * AC7 requires refund, chargeback, vendor payable and payout to be distinct
 * operation types "each with its own authorization, journal shape, and audit".
 * Vendor payable had the journal shape and the audit. This is the authorization.
 *
 * ---------------------------------------------------------------------------
 * Deliberately NOT `PayoutAuthorizer`, and not a widening of it
 * ---------------------------------------------------------------------------
 * Both are scoped to a VENDOR, which makes merging them look tempting. They are
 * different permissions:
 *
 *  - assessing RECOGNISES a debt — it decides that we owe someone;
 *  - a payout DISCHARGES one — it moves money out of the business.
 *
 * Collapsing the two would mean anyone who may record what we owe may also send
 * the money, which is the separation of duties this module's whole shape rests
 * on. Same reasoning `Contracts\ReconciliationAuthorizer` records for its own
 * separation, applied to the other pair.
 *
 * ---------------------------------------------------------------------------
 * Contract terms an implementation must keep
 * ---------------------------------------------------------------------------
 *  1. The acting actor is derived from the authenticated server-side
 *     `ActorContext`. Caller-supplied references or roles must never select an
 *     actor or grant a role (Wave 1b ruling, same as `ManualPayout`).
 *  2. An empty role list is not permission. Absence of information fails
 *     closed, never open.
 *  3. `$vendorId` is RECORD SCOPE, not an actor selector.
 *  4. The returned string is the role the audit trail will record. It must be a
 *     role the implementation actually verified — never a caller's claim, and
 *     never a placeholder chosen because something had to be written.
 *  5. `authorizeUnattended()` must REFUSE when a human is present on the
 *     context. It is the automated path, not a bypass, and its whole value is
 *     that an audit row saying `system` means the system.
 */
interface VendorPayableAuthorizer
{
    /**
     * A HUMAN is recognising this liability. Return the approved role for the
     * server-resolved actor, or refuse.
     *
     * @throws VendorPayableNotAuthorisedException
     */
    public function authorize(ActorContext $actor, string $vendorId): string;

    /**
     * An UNATTENDED, scheduled or queued assessment is recognising this
     * liability — no human triggered it.
     *
     * Return the role the audit trail should record for a genuine system
     * actor. Refuse if the context shows a human is in fact present: a person
     * acting must be authorised as themselves.
     *
     * @throws VendorPayableNotAuthorisedException
     */
    public function authorizeUnattended(ActorContext $actor, string $vendorId): string;
}
