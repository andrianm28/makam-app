<?php

declare(strict_types=1);

namespace App\Domain\RefundObligation\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * Working-day arithmetic for refund deadlines —
 * `docs/superpowers/plans/2026-09-13-sistem-refund.md` §Tenggat.
 *
 * ---------------------------------------------------------------------------
 * Why a seam and not `->addDays(3)` at the call site
 * ---------------------------------------------------------------------------
 * The owner decided on 13 Sep 2026 that a refund obligation must be executed
 * within **3 working days**. Working days, not 72 hours, and the difference is
 * not pedantry: an obligation born Friday afternoon falls due on Wednesday,
 * not Monday. Computed as 72 hours, the system would mark an operator late for
 * work that was not possible to do — and an alarm that is wrong is an alarm
 * people learn to ignore.
 *
 * Nothing in this repository did working-day arithmetic before this class:
 * `businessDay`, `addWeekdays`, `isWeekend` and `holiday` each return zero hits
 * across `app/` and `config/`. So this is a new seam, and the seam — not its
 * callers — is what the tests pin.
 *
 * ---------------------------------------------------------------------------
 * National public holidays are NOT skipped, and that is a stated choice
 * ---------------------------------------------------------------------------
 * Skipping Indonesian public holidays requires an authoritative calendar that
 * this repository does not have and that changes every year. Inventing one
 * would put wrong data into the deadline on somebody's money, which is worse
 * than the documented approximation below.
 *
 * The consequence, stated plainly rather than hidden: **in a week containing a
 * national holiday the deadline is tighter than "3 working days" intends.**
 *
 * The shape of this class is what makes that reversible. Every caller asks for
 * "3 working days from X" and never sees how a working day is decided, so a
 * holiday calendar can be fitted inside {@see self::isWorkingDay()} later
 * without touching a single caller. Doing so is a separate owner decision that
 * arrives with the obligation to supply the calendar source.
 */
final class WorkingDays
{
    /**
     * A hard bound on the forward walk. Not a business rule — a guard so a
     * mistaken caller cannot spin this loop indefinitely. The real deadline
     * this class was built for is 3.
     */
    private const int MAX_DAYS = 365;

    /**
     * The instant `$days` working days after `$from`, preserving the
     * time of day.
     *
     * Counting starts on the day AFTER `$from`: an obligation opened on a
     * Monday is due Thursday, and one opened on a Saturday — when no work is
     * possible — is due Wednesday, the third weekday that follows.
     *
     * @throws InvalidArgumentException when `$days` is not between 1 and
     *                                  {@see self::MAX_DAYS}.
     */
    public static function after(CarbonInterface $from, int $days): CarbonImmutable
    {
        if ($days < 1 || $days > self::MAX_DAYS) {
            throw new InvalidArgumentException(
                "Working-day offset [{$days}] is out of range; expected 1..".self::MAX_DAYS.'.'
            );
        }

        $cursor = CarbonImmutable::instance($from->toDateTimeImmutable());
        $counted = 0;

        while ($counted < $days) {
            $cursor = $cursor->addDay();

            if (self::isWorkingDay($cursor)) {
                $counted++;
            }
        }

        return $cursor;
    }

    /**
     * Whether work is possible on this date.
     *
     * The single place a working day is decided, and therefore the single
     * place a national-holiday calendar would be fitted. See the class doc
     * block for why one is deliberately absent today.
     */
    public static function isWorkingDay(CarbonInterface $date): bool
    {
        return ! $date->isWeekend();
    }
}
