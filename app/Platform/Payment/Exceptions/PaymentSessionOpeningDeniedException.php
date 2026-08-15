<?php

declare(strict_types=1);

namespace App\Platform\Payment\Exceptions;

use App\Platform\Payment\GuardResult;
use RuntimeException;

/**
 * Thrown by `OpenPaymentSession` when the six-condition guard denies the
 * opening.
 *
 * The guard has already done its part before this exception exists: the
 * denial was recorded as a `payment_intents` row with its `PAYMENT_GUARD_DENIED`
 * audit event, in the guard's own transaction. This exception exists to give
 * the HTTP caller an explanatory, public-safe result — the guard's own
 * docblock: "a denial returns an explanatory result, never a silent no-op".
 *
 * The caller should treat it as "use the mandatory manual fallback".
 */
final class PaymentSessionOpeningDeniedException extends RuntimeException
{
    private function __construct(
        string $message,
        private readonly GuardResult $result,
    ) {
        parent::__construct($message);
    }

    public static function forResult(GuardResult $result): self
    {
        return new self(
            'Payment session opening was denied by the six-condition guard: '.$result->publicMessage(),
            $result,
        );
    }

    /**
     * The guard result that denied this opening — the caller can read the
     * public-safe message, the failing condition, and the denial reason.
     */
    public function result(): GuardResult
    {
        return $this->result;
    }

    /**
     * The public-safe message of the first failing condition, same value the
     * guard recorded on the denial intent.
     */
    public function publicMessage(): string
    {
        return $this->result->publicMessage();
    }
}
