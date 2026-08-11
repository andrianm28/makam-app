<?php

declare(strict_types=1);

namespace App\Platform\FinancialLedger\Exceptions;

use RuntimeException;

/**
 * The authenticated actor may not decide reconciliation exceptions for this
 * badan usaha, or the requested exception is intentionally unavailable.
 *
 * Deliberately its OWN exception type rather than a reuse of
 * `PayoutNotAuthorisedException`: reconciliation-decision authority and payout
 * authority are different permissions over different entities (a business
 * entity versus a vendor), and sharing the refusal type is the first step
 * towards sharing the check — which would silently grant payout rights to
 * anyone who can resolve a variance. See
 * `App\Platform\FinancialLedger\FinanceReconciliationAuthorizer` for the
 * mechanism.
 *
 * Fail-closed: no grant row means no authorisation, never "unrestricted until
 * someone configures it". An empty role list is not permission. Resolution
 * callers receive the same opaque message for an unknown id and an id outside
 * their scope, so the action cannot be used to probe exception existence.
 *
 * Names the badan usaha, never the amounts, the statement reference, or any
 * identity detail beyond the actor reference itself.
 */
final class ReconciliationNotAuthorisedException extends RuntimeException
{
    public static function forActorContext(string $entityRef): self
    {
        return new self(
            'The authenticated actor has no explicit finance authority to decide '
            ."reconciliation exceptions for badan usaha [{$entityRef}]."
        );
    }

    public static function forUnavailableException(): self
    {
        return new self(
            'The reconciliation exception is unavailable for this actor.'
        );
    }
}
