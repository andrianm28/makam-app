<?php

declare(strict_types=1);

namespace App\Platform\FinancialLedger\Contracts;

use App\Platform\FinancialLedger\PayoutProof;

/**
 * Narrow seam for the sibling DocumentVault module.
 *
 * Implementations must verify that the opaque reference exists, has the
 * required canonical proof kind, is in `ACCEPTED` state, is stored privately,
 * and belongs to the supplied record scope. This module receives only the
 * opaque reference and never reads or copies document contents.
 */
interface PayoutProofVerifier
{
    public function assertAcceptedPrivateRecordScoped(
        PayoutProof $proof,
        string $recordType,
        string $recordId,
    ): void;
}
