<?php

declare(strict_types=1);

namespace App\Platform\Payment;

/**
 * AC8: "Admin verification SHALL be a separate authorized action" — the
 * closed pair of decisions `VerifyManualPayment` accepts. A caller-facing
 * value distinct from `PaymentVerificationStatus`: the decision is what the
 * admin CHOSE, the status is what the row BECOMES as a result — kept as two
 * types so a future caller cannot pass a status where a decision belongs
 * (there is no `PaymentVerificationDecision::Submitted`, because "submitted"
 * is never a decision anyone makes).
 */
enum PaymentVerificationDecision: string
{
    case Approve = 'approve';
    case Reject = 'reject';

    public function resultingStatus(): PaymentVerificationStatus
    {
        return match ($this) {
            self::Approve => PaymentVerificationStatus::Verified,
            self::Reject => PaymentVerificationStatus::Rejected,
        };
    }
}
