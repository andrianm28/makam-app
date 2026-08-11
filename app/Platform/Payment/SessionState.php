<?php

declare(strict_types=1);

namespace App\Platform\Payment;

use InvalidArgumentException;

/**
 * The closed list of `payment_sessions.state` values —
 * `docs/superpowers/plans/2026-08-09-platform-payment-adapter.md` §File
 * Structure: "closed-list enum `CREATED|AWAITING_PAYMENT|PAID|FAILED|
 * EXPIRED|REFUNDED` (payment-session scope, NOT order scope)".
 *
 * ---------------------------------------------------------------------------
 * "Payment-session scope, NOT order scope" — why that caveat is load-bearing
 * ---------------------------------------------------------------------------
 * `AGENTS.md` §Domain and financial invariants: "Service payment and
 * fulfillment are separate states." The order/invoice vocabulary
 * (`DIBAYAR`, `MENUNGGU_VERIFIKASI_PEMBAYARAN`, and every fulfillment state)
 * belongs to the order aggregate, which this module never writes. A
 * `payment_sessions` row describes ONE provider checkout attempt and nothing
 * about the order it would eventually settle. Adding an order state to this
 * enum would quietly merge the two state machines the invariant separates —
 * see `SessionStateTest` for the test that pins this.
 *
 * ---------------------------------------------------------------------------
 * Nothing writes a row carrying any of these values today
 * ---------------------------------------------------------------------------
 * Wave 1b ruling 1b-L3-01 re-scoped Task 2 to a deny-only guard: no code
 * path creates a `payment_sessions` row, so no state below is ever reached
 * at runtime yet. The enum ships now because the table ships now (the plan
 * assigns both to this lane) and because the closed list is a contract later
 * tasks read, not because any transition exists. See
 * `Models\PaymentSession` for the refusal that enforces that.
 *
 * A plain-string column with application-layer validation, not a Postgres
 * enum type — the same convention as `App\Domain\Booking\BookingServiceType`
 * and `App\Domain\CemeteryDirectory\LaunchCityCode` — plus a real CHECK
 * constraint on Postgres (see the migration).
 */
enum SessionState: string
{
    /**
     * The session row exists locally but no provider session has been
     * confirmed for it yet.
     */
    case Created = 'CREATED';

    /**
     * A hosted checkout exists and the customer has not completed it. The
     * only state a newly created session is expected to reach in the design
     * the plan describes (Task 2's superseded pass path).
     */
    case AwaitingPayment = 'AWAITING_PAYMENT';

    /**
     * Set ONLY from a validated webhook or an approved manual verification
     * (AC4) — never from a browser return URL. No code sets it in this task.
     */
    case Paid = 'PAID';

    /**
     * The provider reported failure. AC11: the order and the draft survive;
     * a recovery path is exposed rather than a dead end.
     */
    case Failed = 'FAILED';

    /**
     * The hosted checkout window elapsed unused.
     */
    case Expired = 'EXPIRED';

    /**
     * A reversal (refund/chargeback/reversal) has been applied against this
     * session. AC12: reversals never mutate history — they post new records
     * referencing the original.
     */
    case Refunded = 'REFUNDED';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    public static function isKnown(string $value): bool
    {
        return self::tryFrom($value) !== null;
    }

    /**
     * @throws InvalidArgumentException when `$value` is not one of this
     *                                  enum's cases.
     */
    public static function assertKnown(string $value): void
    {
        if (! self::isKnown($value)) {
            throw new InvalidArgumentException(
                "Unknown payment session state [{$value}]. Known states: ".implode(', ', self::values()).'.'
            );
        }
    }
}
