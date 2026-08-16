<?php

declare(strict_types=1);

namespace App\Domain\PreNeed;

/**
 * The closed set of installment states on `pre_need_payment_schedules`,
 * named by the plan's Task 3 Produces block: `pending`/`paid`/`overdue`.
 *
 * Deliberately a small enum on this lane: Task 3 only CREATES rows at
 * `pending`; the `paid`/`overdue` movements belong to the later
 * per-installment payment-link and delinquency steps (Task 4), which will
 * move through these same values.
 */
enum PreNeedInstallmentState: string
{
    case PENDING = 'pending';

    case PAID = 'paid';

    case OVERDUE = 'overdue';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $state): string => $state->value, self::cases());
    }
}
