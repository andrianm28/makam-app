<?php

declare(strict_types=1);

namespace App\Platform\Payment;

use InvalidArgumentException;

/**
 * The closed list of `payment_reversals.reversal_type` values — Task 6's
 * safe slice (Wave 1d Append-Correction,
 * `.superpowers/sdd/2026-08-09-platform-payment-adapter/task-6-brief.md`).
 *
 * ---------------------------------------------------------------------------
 * Exactly two cases, deliberately — not a third "Reversal" case
 * ---------------------------------------------------------------------------
 * The original Task 6 plan text names a general "Reversal" category
 * alongside `Refund` and `Chargeback`, but only ever names concrete
 * `SensitiveActions`/audit-action entries for the latter two
 * (`PAYMENT_REFUND`, `PAYMENT_CHARGEBACK`). The Wave 1d ruling is explicit:
 * "Build exactly the two cases the plan names audit actions for ... Do NOT
 * invent a third `PAYMENT_REVERSAL` SensitiveActions constant unilaterally."
 * This is flagged as an open design question for the reviewer in
 * `task-6-report.md`, not resolved here.
 *
 * `payment_reversals` is a self-contained table, decoupled from
 * `payment_verifications`, `payment_sessions`, and any journal batch — see
 * the migration's own doc block for why. This enum therefore shares no
 * case value with `PaymentVerificationStatus` or `SessionState`.
 */
enum PaymentReversalType: string
{
    case Refund = 'REFUND';
    case Chargeback = 'CHARGEBACK';

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
                "Unknown payment reversal type [{$value}]. Known types: ".implode(', ', self::values()).'.'
            );
        }
    }
}
