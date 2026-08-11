<?php

declare(strict_types=1);

namespace App\Platform\Payment;

use InvalidArgumentException;

/**
 * The closed list of `payment_verifications.status` values — Task 5's own,
 * separate state machine (Wave 1c Append-Correction,
 * `.superpowers/sdd/2026-08-09-platform-payment-adapter/task-5-brief.md`:
 * "its own state machine, do NOT reuse or extend `SessionState`").
 *
 * `payment_verifications` is decoupled from `payment_sessions` on purpose
 * (no foreign key, see the migration's own doc block) — a manual proof
 * submission and a hosted-checkout session describe two different things,
 * and merging their vocabularies would quietly recreate the coupling the
 * ruling explicitly forbids. This enum therefore does NOT reuse any
 * `SessionState` case, even where the English gloss looks similar
 * (`SessionState::Paid` vs `self::Verified` — deliberately different words
 * for deliberately different concepts).
 */
enum PaymentVerificationStatus: string
{
    /**
     * The customer submitted a manual payment reference (and optionally a
     * proof document). No decision has been made yet.
     */
    case Submitted = 'SUBMITTED';

    /**
     * An admin approved the submission via `VerifyManualPayment` — this
     * row's own terminal state, not `payment_sessions.state = PAID` and not
     * any order/invoice `DIBAYAR` state (neither is written by this task;
     * see the brief's "Explicitly unavailable and NOT TESTED" section).
     */
    case Verified = 'VERIFIED';

    /**
     * An admin rejected the submission via `VerifyManualPayment`, always
     * with a recorded reason.
     */
    case Rejected = 'REJECTED';

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
                "Unknown payment verification status [{$value}]. Known statuses: ".implode(', ', self::values()).'.'
            );
        }
    }
}
