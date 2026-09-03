<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Booking;

use App\Domain\Booking\BookingWizardStep;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class BookingWizardStepTest extends TestCase
{
    public function test_it_has_exactly_four_steps(): void
    {
        $this->assertSame(4, BookingWizardStep::count());
    }

    public function test_the_constants_are_1_through_4_in_order(): void
    {
        $this->assertSame(1, BookingWizardStep::DISCOVERY);
        $this->assertSame(2, BookingWizardStep::CUSTOMER_AND_DECEASED_DATA);
        $this->assertSame(3, BookingWizardStep::PAYMENT);
        $this->assertSame(4, BookingWizardStep::CONFIRMATION);
    }

    public function test_labels_match_the_four_screen_headings(): void
    {
        $this->assertSame([
            1 => 'Cari & Pilih',
            2 => 'Data Pemesan & Data Almarhum',
            3 => 'Pembayaran',
            4 => 'Konfirmasi',
        ], BookingWizardStep::labels());
    }

    public function test_last_implemented_is_confirmation(): void
    {
        $this->assertSame(BookingWizardStep::CONFIRMATION, BookingWizardStep::LAST_IMPLEMENTED);
    }

    public function test_is_known_rejects_the_old_nine_step_range(): void
    {
        $this->assertTrue(BookingWizardStep::isKnown(4));
        $this->assertFalse(BookingWizardStep::isKnown(5));
        $this->assertFalse(BookingWizardStep::isKnown(9));
        $this->assertFalse(BookingWizardStep::isKnown(0));
    }

    public function test_assert_known_throws_for_an_out_of_range_step(): void
    {
        $this->expectException(InvalidArgumentException::class);
        BookingWizardStep::assertKnown(5);
    }
}
