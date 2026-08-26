<?php

declare(strict_types=1);

namespace App\Domain\OrderWorkflow\Exceptions;

use DomainException;

/**
 * The domain translation of an `order_invoices_reference_unq` violation —
 * the same reasoning
 * `App\Domain\AgreementCertificate\Exceptions\CertificateReferenceCollisionException`
 * documents at length, and for the same two reasons: a caller cannot
 * distinguish "this reference already exists" from any other write
 * failure once it is a driver-specific `QueryException`, and that
 * exception's formatted message interpolates the failing INSERT's
 * bindings — logging it verbatim would put the generated reference and
 * the order id into the log unnecessarily. The originating
 * `QueryException` is deliberately NOT chained as `$previous` for that
 * reason.
 */
final class InvoiceReferenceCollisionException extends DomainException
{
    public static function forOrder(string $orderId): self
    {
        return new self(
            "An invoice reference collision was detected while issuing an invoice for order [{$orderId}]."
        );
    }
}
