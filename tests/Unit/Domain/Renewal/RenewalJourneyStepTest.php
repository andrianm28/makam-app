<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Renewal;

use App\Domain\Renewal\RenewalJourneyStep;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class RenewalJourneyStepTest extends TestCase
{
    public function test_it_has_exactly_three_steps(): void
    {
        $this->assertSame(3, RenewalJourneyStep::count());
    }

    public function test_the_constants_are_1_through_3_in_order(): void
    {
        $this->assertSame(1, RenewalJourneyStep::SEARCH);
        $this->assertSame(2, RenewalJourneyStep::FEE_AND_PAYMENT);
        $this->assertSame(3, RenewalJourneyStep::CONFIRMATION);
    }

    public function test_labels_match_the_three_merged_headings(): void
    {
        $this->assertSame([
            1 => 'Cari Makam',
            2 => 'Biaya & Bayar',
            3 => 'Konfirmasi',
        ], RenewalJourneyStep::labels());
    }

    public function test_is_known_rejects_the_old_six_step_range(): void
    {
        $this->assertTrue(RenewalJourneyStep::isKnown(3));
        $this->assertFalse(RenewalJourneyStep::isKnown(4));
        $this->assertFalse(RenewalJourneyStep::isKnown(6));
    }

    public function test_assert_known_throws_for_an_out_of_range_step(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RenewalJourneyStep::assertKnown(4);
    }
}
