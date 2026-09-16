<?php

declare(strict_types=1);

namespace App\Domain\RefundObligation;

/**
 * The closed list of `refund_obligations.status` values —
 * `docs/superpowers/plans/2026-09-13-sistem-refund.md` Stage R0.
 *
 * ---------------------------------------------------------------------------
 * Three cases, forward only, and no way to close an obligation by decree
 * ---------------------------------------------------------------------------
 * `TERUTANG` (owed) → `DIEKSEKUSI` (the operator has moved the money and
 * recorded evidence) → `TERKONFIRMASI` (receipt confirmed). Those are three
 * separate real-world events, and collapsing the last two would let "we sent
 * it" stand in for "they got it".
 *
 * There is deliberately **no** cancelled/void/expired case. The plan's binding
 * invariant reads: *"tidak ada yang menutup kewajiban kecuali eksekusi yang
 * tercatat beserta buktinya. Bukan admin yang menandai selesai. Bukan
 * kedaluwarsa."* A void case would be exactly the escape hatch that invariant
 * exists to deny — a debt to a grieving family disappearing because somebody
 * marked it gone. If a refund genuinely turns out not to be owed, that is a
 * new decision needing its own design and its own review, not a status value
 * quietly available to every caller from day one.
 *
 * Backed enum rather than a constants class (the choice
 * `App\Domain\PlotReservation\PlotReservationState` made for its own reasons):
 * these values are cast on the model and compared as enum cases, following
 * `App\Domain\OrderWorkflow\OrderStatus` and
 * `App\Platform\Payment\PaymentReversalType`.
 */
enum RefundObligationStatus: string
{
    case TERUTANG = 'TERUTANG';

    case DIEKSEKUSI = 'DIEKSEKUSI';

    case TERKONFIRMASI = 'TERKONFIRMASI';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /**
     * Whether money is still owed to the customer in this state.
     *
     * `DIEKSEKUSI` is NOT settled: the transfer has been made and evidenced,
     * but nobody has confirmed it landed. It is the deadline that stops
     * applying at `DIEKSEKUSI`, not the obligation.
     */
    public function isOutstanding(): bool
    {
        return $this === self::TERUTANG;
    }

    /**
     * The states an obligation may move to from this one. Empty for the
     * terminal state.
     *
     * @return list<self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::TERUTANG => [self::DIEKSEKUSI],
            self::DIEKSEKUSI => [self::TERKONFIRMASI],
            self::TERKONFIRMASI => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedNext(), true);
    }
}
