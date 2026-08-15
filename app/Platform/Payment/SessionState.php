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
 * Only a validated webhook moves a session out of the open states
 * ---------------------------------------------------------------------------
 * Wave 1b ruling 1b-L3-01 made the payment guard deny-only until the
 * confirmation/reservation, quote, opening-authorization, and
 * merchant/`badan_usaha` upstreams exist, and the online-payment-gateway
 * lane later opened the gate under `G-PAY-01` (dev), so a row can now be
 * created — and the terminal states below are no longer a dead letter. The
 * ONLY writer of the terminal states is `Actions\ApplyPaymentSettlement`
 * (Task 5 of `docs/superpowers/plans/2026-08-14-online-payment-gateway.md`),
 * which runs inside `ProcessWebhookEvent`'s claim transaction: a settled
 * `payment.completed` moves the session to `PAID`, a claimed `payment.failed`
 * to `FAILED`, a claimed `payment.expired` to `EXPIRED` — never from a
 * browser return URL, and guarded so `PAID` is never regressed by a late
 * out-of-order `failed`/`expired` arrival.
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
     * (AC4) — never from a browser return URL. The webhook half is live:
     * `Actions\ApplyPaymentSettlement` moves the session here when a claimed
     * `payment.completed` settles (this is what the checkout return screen
     * reads). The approved-manual-verification half stays open — that trigger
     * site is unwired.
     */
    case Paid = 'PAID';

    /**
     * The provider reported failure. AC11: the order and the draft survive;
     * a recovery path is exposed rather than a dead end. Set by
     * `Actions\ApplyPaymentSettlement` when a claimed `payment.failed` event
     * is processed.
     */
    case Failed = 'FAILED';

    /**
     * The hosted checkout window elapsed unused. Set by
     * `Actions\ApplyPaymentSettlement` when a claimed `payment.expired` event
     * is processed.
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
