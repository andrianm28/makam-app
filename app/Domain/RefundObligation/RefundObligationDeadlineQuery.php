<?php

declare(strict_types=1);

namespace App\Domain\RefundObligation;

use Illuminate\Support\Facades\DB;

/**
 * Which refund debts are running out of time, and which have run out.
 *
 * One query class, two questions, so the console watchdog and the admin
 * dashboard widget cannot drift into disagreeing about what "late" means.
 *
 * ---------------------------------------------------------------------------
 * Why there are TWO thresholds and not just "overdue"
 * ---------------------------------------------------------------------------
 * The refund plan's R4 says overdue obligations must be loud. That alone is
 * not enough, and the reason is a fact the owner supplied on 14 Sep 2026:
 * SumoPod supports **withdraw to the main account only**, so a refund is two
 * manual bank movements —
 *
 *     SumoPod --withdraw--> our main account --transfer--> customer
 *
 * The withdraw leg settles on the provider's clock, not ours. So an operator
 * who first learns about a debt ON its deadline has already lost the part of
 * the window they needed most: they cannot start a withdraw and finish a
 * transfer in zero time. An alarm that fires exactly at `due_at` is an alarm
 * that fires after it could have helped.
 *
 * Hence `dueSoon()` — the debts still inside their window but close enough
 * that the withdraw must start now — reported separately from `overdue()`,
 * which is the failure that has already happened. Two different actions:
 * one is "begin the withdraw", the other is "this family has been waiting
 * too long, and someone must be told why".
 *
 * ---------------------------------------------------------------------------
 * Stateless, like every other watchdog signal
 * ---------------------------------------------------------------------------
 * Both methods are plain counts against `(status, due_at)` — the composite
 * index R0's migration created for exactly this sweep. No "since last run"
 * bookkeeping, no cache key, no marker column: `SpineWatchdogCommand`'s own
 * doc block explains why its signals avoid those, and the reasoning carries
 * over unchanged — a stored cursor is one more thing that can itself stop
 * working silently, which is the entire failure class this watchdog exists
 * to catch.
 *
 * Only `TERUTANG` rows are ever counted. An obligation that reached
 * `DIEKSEKUSI` has had its money sent and its evidence recorded; it is not
 * late, whatever its `due_at` says.
 */
final readonly class RefundObligationDeadlineQuery
{
    /**
     * How close to `due_at` counts as "start the withdraw now".
     *
     * A working day, expressed in hours. Not tuned — it is the smallest
     * honest unit given that the deadline itself is counted in working days
     * (`OpenRefundObligation::EXECUTION_DEADLINE_WORKING_DAYS`), and a
     * warning shorter than one working day could arrive on a Friday evening
     * for a Monday deadline and be seen by nobody.
     */
    public const int DUE_SOON_HOURS = 24;

    /**
     * Debts still inside their deadline, but close enough that the first of
     * the two bank movements has to begin.
     */
    public function dueSoonCount(int $withinHours = self::DUE_SOON_HOURS): int
    {
        $now = now();

        return DB::table('refund_obligations')
            ->where('status', RefundObligationStatus::TERUTANG->value)
            ->where('due_at', '>=', $now)
            ->where('due_at', '<', $now->copy()->addHours($withinHours))
            ->count();
    }

    /**
     * Debts whose deadline has passed and which are still unpaid.
     *
     * The plan's sentence for this state is worth keeping next to the code
     * that detects it: *"Kewajiban yang diam adalah kewajiban yang
     * dilupakan, dan yang menanggung lupanya adalah keluarga yang sudah
     * membayar."*
     */
    public function overdueCount(): int
    {
        return DB::table('refund_obligations')
            ->where('status', RefundObligationStatus::TERUTANG->value)
            ->where('due_at', '<', now())
            ->count();
    }

    /**
     * The single oldest unpaid debt's deadline, for a message that says how
     * bad it is rather than only that it is bad.
     */
    public function oldestOverdueDueAt(): ?string
    {
        /** @var object{due_at: string}|null $row */
        $row = DB::table('refund_obligations')
            ->where('status', RefundObligationStatus::TERUTANG->value)
            ->where('due_at', '<', now())
            ->orderBy('due_at')
            ->first(['due_at']);

        return $row?->due_at;
    }
}
