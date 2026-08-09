<?php

declare(strict_types=1);

namespace App\Platform\FinancialLedger\Contracts;

use App\Platform\FinancialLedger\PayoutProof;

/**
 * Narrow seam for the sibling DocumentVault module.
 *
 * Implementations must verify the canonical document kind, private accepted
 * state, and owner scope for the supplied record. This module receives only
 * the opaque reference and never reads or copies document contents.
 */
interface PayoutProofVerifier
{
    public function assertAccepted(PayoutProof $proof, string $recordType, string $recordId): void;
}
