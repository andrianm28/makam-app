<?php

declare(strict_types=1);

namespace App\Platform\Payment\Exceptions;

use RuntimeException;

/**
 * Thrown by `VerifyManualPayment` when an ADMIN attempts to approve a
 * `payment_verifications` row that carries no `order_id` or no
 * `amount_minor`.
 *
 * `SubmitManualPayment` refuses to create a row without both (see that
 * class and `2026_08_26_120000_add_order_link_and_amount_to_payment_
 * verifications_table.php`'s own doc block on why the columns stayed
 * nullable at the schema level regardless), so in practice this only
 * fires for a row that predates this change — a real submission that was
 * accepted before the linkage/amount became mandatory. Refusing to approve
 * such a row is the fail-closed choice: there is nothing to assert the paid
 * amount against, so approving it would mean marking an order paid on
 * trust rather than on a verified fact. Rejecting such a row remains
 * possible — rejection never touches the order.
 */
final class PaymentVerificationMissingLinkageException extends RuntimeException
{
    public static function forVerification(string $verificationId): self
    {
        return new self(
            "Cannot approve payment verification [{$verificationId}]: it has no linked order or amount to verify "
            .'against (it predates PAY-02\'s order-linkage requirement).'
        );
    }
}
