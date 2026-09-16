<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\RefundObligation;

use App\Domain\RefundObligation\Support\WorkingDays;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * The seam the owner's "3 hari kerja" decision rests on —
 * `docs/superpowers/plans/2026-09-13-sistem-refund.md` §Tenggat.
 *
 * These tests pin behaviour by naming real weekdays, not by re-implementing
 * the arithmetic. Every expected date below was picked because a plain
 * `addDays(3)` gets it wrong.
 */
final class WorkingDaysTest extends TestCase
{
    /**
     * The case that makes "working days" different from "72 hours". An
     * obligation opened Friday afternoon is due Wednesday. Computed as three
     * calendar days it would be due Monday, and the operator would be marked
     * late for two days on which no work was possible.
     */
    public function test_friday_afternoon_plus_three_working_days_is_wednesday(): void
    {
        // Friday 11 Sep 2026, 16:30.
        $friday = CarbonImmutable::parse('2026-09-11 16:30:00');
        self::assertSame('Friday', $friday->format('l'), 'fixture drifted');

        $due = WorkingDays::after($friday, 3);

        self::assertSame('2026-09-16 16:30:00', $due->format('Y-m-d H:i:s'));
        self::assertSame('Wednesday', $due->format('l'));
    }

    public function test_monday_plus_three_working_days_is_thursday(): void
    {
        $monday = CarbonImmutable::parse('2026-09-07 09:00:00');
        self::assertSame('Monday', $monday->format('l'), 'fixture drifted');

        $due = WorkingDays::after($monday, 3);

        self::assertSame('2026-09-10 09:00:00', $due->format('Y-m-d H:i:s'));
        self::assertSame('Thursday', $due->format('l'));
    }

    /**
     * An obligation can be opened at the weekend — a customer pays whenever
     * they pay, and an automatic rejection does not wait for Monday. Counting
     * starts on the next day and only weekdays count, so Saturday is due
     * Wednesday, exactly like Friday.
     */
    public function test_saturday_plus_three_working_days_is_wednesday(): void
    {
        $saturday = CarbonImmutable::parse('2026-09-12 23:59:00');
        self::assertSame('Saturday', $saturday->format('l'), 'fixture drifted');

        $due = WorkingDays::after($saturday, 3);

        self::assertSame('Wednesday', $due->format('l'));
        self::assertSame('2026-09-16 23:59:00', $due->format('Y-m-d H:i:s'));
    }

    /**
     * The whole point of the seam: no weekend day is ever a deadline. Walked
     * across a full year so a bug that only bites in a particular month
     * cannot hide.
     */
    public function test_no_deadline_ever_lands_on_a_weekend(): void
    {
        $cursor = CarbonImmutable::parse('2026-01-01 10:00:00');

        for ($i = 0; $i < 365; $i++) {
            $due = WorkingDays::after($cursor, 3);

            self::assertFalse(
                $due->isWeekend(),
                "3 working days after {$cursor->toDateString()} landed on {$due->format('l Y-m-d')}"
            );

            $cursor = $cursor->addDay();
        }
    }

    /**
     * Guards the documented limitation rather than leaving it implied. 17
     * August is Indonesian Independence Day; the plan states plainly that
     * national holidays are NOT skipped and why. If somebody later fits a
     * holiday calendar into `isWorkingDay()`, this test fails and forces them
     * to update the plan's stated consequence at the same time.
     */
    public function test_national_holidays_are_not_skipped_as_the_plan_states(): void
    {
        // Monday 17 Aug 2026 — Hari Kemerdekaan.
        $independenceDay = CarbonImmutable::parse('2026-08-17 09:00:00');
        self::assertSame('Monday', $independenceDay->format('l'), 'fixture drifted');

        self::assertTrue(
            WorkingDays::isWorkingDay($independenceDay),
            'National holidays are deliberately not skipped — see the plan. '
            .'If that changed, the plan document must change with it.'
        );
    }

    public function test_an_offset_below_one_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        WorkingDays::after(CarbonImmutable::parse('2026-09-07 09:00:00'), 0);
    }

    public function test_an_absurd_offset_is_refused_rather_than_walked(): void
    {
        $this->expectException(InvalidArgumentException::class);

        WorkingDays::after(CarbonImmutable::parse('2026-09-07 09:00:00'), 366);
    }
}
