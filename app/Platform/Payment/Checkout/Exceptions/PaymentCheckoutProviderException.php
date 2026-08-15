<?php

declare(strict_types=1);

namespace App\Platform\Payment\Checkout\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Thrown when the payment provider answers anything other than a well-formed
 * success for `POST /api/v1/payments`.
 *
 * Covers, without exception: a non-2xx status, a body that is not JSON or
 * lacks a required field, and a transport failure. Callers must not be able
 * to distinguish a forged "success" from a real one — the provider is the
 * only authority on whether a payment was created, and anything less than a
 * complete, well-formed 2xx is a refusal.
 *
 * The provider URL and payment data are deliberately absent from the message:
 * never place restricted data in logs or error trackers (AC14 / `AGENTS.md`
 * §Observability).
 */
final class PaymentCheckoutProviderException extends RuntimeException
{
    public static function forStatus(int $status): self
    {
        return new self(sprintf('The payment provider returned HTTP %d for the create-payment request.', $status));
    }

    public static function becauseMalformedResponse(): self
    {
        return new self('The payment provider returned a malformed response for the create-payment request.');
    }

    public static function becauseProviderUnreachable(Throwable $previous): self
    {
        return new self('The payment provider could not be reached for the create-payment request.', 0, $previous);
    }
}
