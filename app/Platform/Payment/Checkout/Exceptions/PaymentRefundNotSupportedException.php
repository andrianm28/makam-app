<?php

declare(strict_types=1);

namespace App\Platform\Payment\Checkout\Exceptions;

use RuntimeException;

/**
 * Thrown when the active payment provider cannot reverse a collection at all.
 *
 * Not "the request failed" and not "try again" — a permanent property of the
 * provider, known before any HTTP request is made. Callers must not retry it,
 * must not treat it as transient, and must not swallow it: an obligation that
 * cannot be paid back automatically still has to be paid back, by a person,
 * which is what the refund plan's manual execution stage exists for.
 *
 * ---------------------------------------------------------------------------
 * Why this exists rather than a silent no-op or a `false` return
 * ---------------------------------------------------------------------------
 * The refund plan states the rule directly: an interface with no
 * implementation "must not be read as an existing refund capability". A
 * method that returned `false`, or that succeeded while doing nothing, would
 * be read exactly that way by the next person wiring an automated refund — and
 * on a money path the failure mode is a customer who is told their refund was
 * issued and never receives it.
 *
 * `PaymentCheckoutClient::supportsRefund()` is the way to ASK without
 * catching. This exception is what happens to code that did not ask.
 *
 * Same posture as its siblings in this namespace: the module prefers a loud
 * refusal over a silently broken money path.
 */
final class PaymentRefundNotSupportedException extends RuntimeException
{
    public static function forProvider(string $provider): self
    {
        return new self(
            "The active payment provider [{$provider}] cannot reverse a payment. This is a "
            .'permanent property of the provider, not a transient failure: no request was made '
            .'and retrying will not change it. The obligation must be settled through the manual '
            .'refund-execution path, which records the transfer and its evidence.'
        );
    }
}
