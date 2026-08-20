<?php

declare(strict_types=1);

namespace App\Platform\Payment\Exceptions;

use App\Platform\Payment\GuardResult;
use LogicException;
use RuntimeException;

/**
 * Thrown by `OpenPaymentSession` when a payment-opening guard denies the
 * opening — the booking six-condition guard (`GuardPaymentSession`) or, since
 * the marketplace follow-up landed, `App\Domain\Marketplace\Actions\
 * GuardMarketplacePaymentOpening`.
 *
 * The guard has already done its part before this exception exists: the
 * denial was recorded — a `payment_intents` row with its
 * `PAYMENT_GUARD_DENIED` audit event for booking (in the guard's own
 * transaction), or a `MARKETPLACE_PAYMENT_OPENING_DENIED` audit event for
 * marketplace (see that guard's own class doc block for why it does not
 * share booking's `payment_intents` row shape). This exception exists to
 * give the HTTP caller an explanatory, public-safe result — the booking
 * guard's own docblock: "a denial returns an explanatory result, never a
 * silent no-op".
 *
 * The caller should treat it as "use the mandatory manual fallback".
 *
 * ---------------------------------------------------------------------------
 * Two factories: `forResult()` (booking) and `forPublicMessage()` (any other
 * order type)
 * ---------------------------------------------------------------------------
 * `forResult()` is unchanged from before the marketplace follow-up — every
 * existing booking call site and test keeps working identically.
 * `forPublicMessage()` is the generic factory a non-`GuardResult`-shaped
 * guard uses: `result()` is a booking-only accessor and throws
 * `LogicException` when called on an instance built the generic way, so a
 * caller cannot silently mistake a marketplace denial for a booking one by
 * reaching for the wrong accessor.
 */
final class PaymentSessionOpeningDeniedException extends RuntimeException
{
    private function __construct(
        string $message,
        private readonly ?GuardResult $result,
        private readonly string $publicMessage,
    ) {
        parent::__construct($message);
    }

    public static function forResult(GuardResult $result): self
    {
        return new self(
            'Payment session opening was denied by the six-condition guard: '.$result->publicMessage(),
            $result,
            $result->publicMessage(),
        );
    }

    /**
     * The generic factory for an order type whose guard does not produce a
     * `GuardResult` — see class doc block.
     */
    public static function forPublicMessage(string $publicMessage): self
    {
        return new self(
            'Payment session opening was denied: '.$publicMessage,
            null,
            $publicMessage,
        );
    }

    /**
     * The guard result that denied this opening — the caller can read the
     * public-safe message, the failing condition, and the denial reason.
     *
     * @throws LogicException when this instance was built via
     *                        `forPublicMessage()` — there is no `GuardResult`
     *                        to return.
     */
    public function result(): GuardResult
    {
        if ($this->result === null) {
            throw new LogicException(
                'No GuardResult is available: this exception was constructed with forPublicMessage() '
                .'for a non-booking order type. Use publicMessage() instead.'
            );
        }

        return $this->result;
    }

    /**
     * The public-safe denial message — the guard's own message for a
     * booking denial (`GuardResult::publicMessage()`), or the message passed
     * to `forPublicMessage()` for any other order type.
     */
    public function publicMessage(): string
    {
        return $this->publicMessage;
    }
}
