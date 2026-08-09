<?php

declare(strict_types=1);

namespace App\Platform\FinancialLedger\Exceptions;

use RuntimeException;

final class PayoutProofNotAcceptedException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(
            'Payout proof must be an accepted, private, record-scoped DocumentVault document.'
        );
    }
}
