<?php

declare(strict_types=1);

namespace App\Platform\FinancialLedger\Exceptions;

use RuntimeException;

/**
 * The authenticated actor may not read the ledger for the requested badan
 * usaha — or holds no finance authority over any badan usaha at all.
 *
 * Its own type rather than a reuse of `ReconciliationNotAuthorisedException`,
 * for the same reason that one is not a reuse of the payout exception: reading
 * a whole period's books and deciding a single variance are different
 * permissions, and sharing the refusal type is the first step towards sharing
 * the check.
 *
 * Fail-closed: no grant row means no authorisation, never "unrestricted until
 * someone configures it", and an empty role list is not permission. The
 * refusal message is the same whether the requested badan usaha does not exist
 * or merely sits outside the actor's grants, so the export cannot be used to
 * probe which badan usaha this deployment holds books for.
 *
 * Carries no amounts, no account codes, and no identity detail.
 */
final class LedgerReadNotAuthorisedException extends RuntimeException
{
    public static function forActorContext(): self
    {
        return new self(
            'The authenticated actor has no explicit finance authority to read '
            .'the financial ledger for the requested badan usaha.'
        );
    }
}
