<?php

declare(strict_types=1);

namespace App\Platform\FinancialLedger;

use App\Platform\FinancialLedger\Contracts\PayoutProofVerifier;
use App\Platform\FinancialLedger\Exceptions\PayoutProofNotAcceptedException;

/**
 * Safe default until the DocumentVault adapter is bound after the sibling
 * module merges. A nonblank reference is never treated as proof by default.
 */
final class RejectingPayoutProofVerifier implements PayoutProofVerifier
{
    public function assertAccepted(PayoutProof $proof, string $recordType, string $recordId): void
    {
        throw new PayoutProofNotAcceptedException;
    }
}
